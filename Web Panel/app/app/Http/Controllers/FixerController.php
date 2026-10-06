<?php

namespace App\Http\Controllers;

use App\Models\LogConnection;
use App\Models\Settings;
use App\Models\Traffic;
use App\Models\Users;
use Illuminate\Support\Facades\Process;

class FixerController extends Controller
{
    public function cronexp(): void
    {
        if (env('ANTI_USER', 'active') === 'active') {
            $home = Process::run(['ls', '-1', '/home'])->output();
            foreach (preg_split('/\R/', trim($home)) as $username) {
                if ($username === '' || in_array($username, ['ubuntu', 'videocall'], true)) {
                    continue;
                }
                if (!Users::where('username', $username)->exists()) {
                    Process::run(['sudo', '/usr/local/sbin/xpanel-userctl', 'delete', $username]);
                }
            }
        }

        $users = Users::where('status', 'active')->get();
        foreach ($users as $user) {
            if (!empty($user->end_date) && now()->startOfDay()->gte($user->end_date)) {
                Process::run(['sudo', '/usr/local/sbin/xpanel-userctl', 'delete', $user->username]);
                $user->update(['status' => 'expired']);
                continue;
            }

            $traffic = Traffic::where('username', $user->username)->first();
            if ($traffic && $user->traffic > 0 && $traffic->total >= $user->traffic) {
                Process::run(['sudo', '/usr/local/sbin/xpanel-userctl', 'delete', $user->username]);
                $user->update(['status' => 'traffic']);
            }
        }
    }

    public function multiuser(): void
    {
        $settings = Settings::first();
        if (!$settings || $settings->multiuser !== 'active') {
            return;
        }

        $port = (int) env('PORT_SSH', 22);
        $result = Process::run(['sudo', '/usr/local/sbin/xpanel-userctl', 'online-port', (string) $port]);
        $online = [];

        foreach (preg_split('/\R/', trim($result->output())) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 3 || !isset($parts[2])) {
                continue;
            }
            $username = $parts[2];
            if ($username === 'root' || !preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $username)) {
                continue;
            }
            $online[$username] = ($online[$username] ?? 0) + 1;
        }

        $known = LogConnection::pluck('username')->all();
        foreach ($known as $username) {
            if (!isset($online[$username])) {
                LogConnection::where('username', $username)->update(['connection' => 0]);
            }
        }

        foreach ($online as $username => $count) {
            $user = Users::where('username', $username)->first();
            if (!$user) {
                continue;
            }

            $log = LogConnection::firstOrNew(['username' => $username]);
            $log->connection = $count;
            $log->datecon = now()->format('Y-m-d H:i');
            $log->save();

            if ((int) $user->multiuser > 0 && $count > (int) $user->multiuser) {
                Process::run(['sudo', '/usr/local/sbin/xpanel-userctl', 'kill-user', $username]);
            }
        }
    }

    public function other(): void
    {
        if (env('CRON_TRAFFIC', 'active') === 'active') {
            $this->synstraffics();
        }
        $this->multiuser();
    }

    public function synstraffics(): void
    {
        $path = storage_path('out.json');
        if (is_file($path)) {
            $entries = array_values(array_filter(preg_split('/\R/', file_get_contents($path))));
            $last = end($entries);
            $data = json_decode($last ?: '', true);

            if (is_array($data)) {
                $base = max(1, (float) env('TRAFFIC_BASE', 12));
                $totals = [];

                foreach ($data as $entry) {
                    $name = preg_replace('/\s+/', '', (string) ($entry['name'] ?? ''));
                    $name = str_replace('sshd:', '', $name);
                    $rx = (float) ($entry['RX'] ?? 0);
                    $tx = (float) ($entry['TX'] ?? 0);
                    if ($name === '' || !preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $name) || ($rx < 1 && $tx < 1)) {
                        continue;
                    }
                    $totals[$name]['rx'] = ($totals[$name]['rx'] ?? 0) + $rx;
                    $totals[$name]['tx'] = ($totals[$name]['tx'] ?? 0) + $tx;
                }

                foreach ($totals as $username => $value) {
                    $traffic = Traffic::where('username', $username)->first();
                    if (!$traffic) {
                        continue;
                    }
                    $download = round(($value['rx'] / 10 / $base) * 100);
                    $upload = round(($value['tx'] / 10 / $base) * 100);
                    $traffic->update([
                        'download' => (int) $traffic->download + $download,
                        'upload' => (int) $traffic->upload + $upload,
                        'total' => (int) $traffic->total + $download + $upload,
                    ]);
                }
            }
        }

        Process::run(['sudo', '/usr/local/sbin/xpanel-userctl', 'traffic-snapshot']);
    }
}
