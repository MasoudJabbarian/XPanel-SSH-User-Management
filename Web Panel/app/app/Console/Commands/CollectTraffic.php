<?php

namespace App\Console\Commands;

use App\Models\Traffic;
use App\Models\Users;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class CollectTraffic extends Command
{
    protected $signature = 'traffic:collect';
    protected $description = 'Collect SSH traffic per Linux user';

    private const BYTES_PER_MB = 1048576;

    public function handle(): int
    {
        if (env('CRON_TRAFFIC', 'active') !== 'active') {
            return self::SUCCESS;
        }

        $result = Process::run([
            'sudo',
            '/usr/local/sbin/xpanel-userctl',
            'traffic-snapshot',
        ]);

        if (!$result->successful()) {
            $this->error('Traffic snapshot failed.');
            return self::FAILURE;
        }

        $path = storage_path('out.json');
        if (!is_file($path)) {
            $this->error('Traffic snapshot file is missing.');
            return self::FAILURE;
        }

        $entries = $this->lastJsonSnapshot((string) file_get_contents($path));
        if ($entries === null) {
            $this->error('No valid NetHogs JSON snapshot found.');
            return self::FAILURE;
        }

        $knownUsers = Users::pluck('username')->mapWithKeys(
            fn ($username) => [strtolower($username) => $username]
        )->all();

        $totals = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $rx = $this->number($entry['RX'] ?? $entry['rx'] ?? null);
            $tx = $this->number($entry['TX'] ?? $entry['tx'] ?? null);
            if ($rx === null || $tx === null || ($rx <= 0 && $tx <= 0)) {
                continue;
            }

            $username = $this->resolveUsername($entry, $knownUsers);
            if ($username === null) {
                continue;
            }

            if (!isset($totals[$username])) {
                $totals[$username] = ['download' => 0.0, 'upload' => 0.0];
            }

            $totals[$username]['download'] += $rx / self::BYTES_PER_MB;
            $totals[$username]['upload'] += $tx / self::BYTES_PER_MB;
        }

        foreach ($totals as $username => $values) {
            $download = round($values['download'], 3);
            $upload = round($values['upload'], 3);
            $total = round($download + $upload, 3);

            $traffic = Traffic::firstOrCreate(
                ['username' => $username],
                ['download' => 0, 'upload' => 0, 'total' => 0]
            );

            $traffic->update([
                'download' => round((float) $traffic->download + $download, 3),
                'upload' => round((float) $traffic->upload + $upload, 3),
                'total' => round((float) $traffic->total + $total, 3),
            ]);
        }

        $this->info(sprintf('Updated traffic for %d SSH users.', count($totals)));
        return self::SUCCESS;
    }

    private function lastJsonSnapshot(string $output): ?array
    {
        $last = null;

        foreach (preg_split('/\R/', $output) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $last = $decoded;
            }
        }

        if ($last === null) {
            return null;
        }

        if ($this->isEntryList($last)) {
            return $last;
        }

        foreach (['data', 'entries', 'processes', 'connections'] as $key) {
            if (isset($last[$key]) && is_array($last[$key]) && $this->isEntryList($last[$key])) {
                return $last[$key];
            }
        }

        return null;
    }

    private function isEntryList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        foreach ($value as $entry) {
            if (!is_array($entry)) {
                return false;
            }

            if (
                array_key_exists('PID', $entry) ||
                array_key_exists('pid', $entry) ||
                array_key_exists('name', $entry) ||
                array_key_exists('NAME', $entry)
            ) {
                continue;
            }

            return false;
        }

        return true;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function resolveUsername(array $entry, array $knownUsers): ?string
    {
        $candidates = [
            $entry['user'] ?? null,
            $entry['USER'] ?? null,
        ];

        $name = (string) ($entry['name'] ?? $entry['NAME'] ?? '');

        if ($name !== '') {
            if (preg_match('/(?:^|[\\s:])sshd(?:\\[[0-9]+\\])?:[\\s]*([a-z_][a-z0-9_-]{0,31})@/i', $name, $m)) {
                $candidates[] = $m[1];
            }

            if (preg_match('/^([a-z_][a-z0-9_-]{0,31})@(?:pts|notty)\\b/i', $name, $m)) {
                $candidates[] = $m[1];
            }
        }

        $pid = $entry['PID'] ?? $entry['pid'] ?? null;
        if (is_numeric($pid) && (int) $pid > 0) {
            $uid = $this->processUid((int) $pid);
            if ($uid !== null && function_exists('posix_getpwuid')) {
                $account = posix_getpwuid($uid);
                if (is_array($account) && isset($account['name'])) {
                    $candidates[] = $account['name'];
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $candidate = strtolower(trim($candidate));
            if (isset($knownUsers[$candidate])) {
                return $knownUsers[$candidate];
            }
        }

        return null;
    }

    private function processUid(int $pid): ?int
    {
        $status = "/proc/{$pid}/status";
        if (!is_readable($status)) {
            return null;
        }

        $contents = file_get_contents($status);
        if ($contents === false || !preg_match('/^Uid:\s+(\\d+)/m', $contents, $m)) {
            return null;
        }

        return (int) $m[1];
    }
}
