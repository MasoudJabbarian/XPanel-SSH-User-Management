<?php

namespace App\Console\Commands;

use App\Models\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class BackupRemote extends Command
{
    protected $signature = 'backup:remote';
    protected $description = 'Create and upload a remote XPanel database backup';

    public function handle(): int
    {
        $settings = Settings::first();

        if (!$settings || !$settings->remote_backup_enabled) {
            return self::SUCCESS;
        }

        $interval = max(1, (int) $settings->remote_backup_interval_hours);
        if ($settings->remote_backup_last_at &&
            now()->lt($settings->remote_backup_last_at->copy()->addHours($interval))) {
            return self::SUCCESS;
        }

        $settings->update([
            'remote_backup_last_at' => now(),
            'remote_backup_last_status' => 'running',
            'remote_backup_last_message' => null,
        ]);

        $dumpPath = storage_path('app/remote-backup-' . now()->format('Ymd-His') . '.sql');
        $defaultsPath = storage_path('app/.remote-backup-' . bin2hex(random_bytes(8)));

        try {
            $host = trim((string) $settings->remote_backup_host);
            $folder = trim((string) ($settings->remote_backup_folder ?? ''), '/');
            $username = trim((string) $settings->remote_backup_username);
            $password = (string) $settings->remote_backup_password;
            $port = (int) $settings->remote_backup_port;

            if ($host === '' || $username === '' || $password === '') {
                throw new \RuntimeException('Remote backup settings are incomplete.');
            }

            $database = env('DB_DATABASE');
            $dbUser = env('DB_USERNAME');
            $dbPassword = env('DB_PASSWORD');
            $dbHost = env('DB_HOST', '127.0.0.1');
            $dbPort = env('DB_PORT', '3306');

            if (!$database || !$dbUser) {
                throw new \RuntimeException('Database settings are incomplete.');
            }

            file_put_contents($defaultsPath, implode(PHP_EOL, [
                '[client]',
                'host=' . $dbHost,
                'port=' . $dbPort,
                'user=' . $dbUser,
                'password=' . $dbPassword,
                '',
            ]));
            chmod($defaultsPath, 0600);

            $dump = Process::run([
                '/usr/bin/mysqldump',
                '--defaults-extra-file=' . $defaultsPath,
                '--single-transaction',
                '--quick',
                '--routines',
                '--triggers',
                $database,
            ]);

            if ($dump->failed()) {
                throw new \RuntimeException(trim($dump->errorOutput()) ?: 'Database backup failed.');
            }

            file_put_contents($dumpPath, $dump->output());

            $host = preg_replace('#^ftps?://#i', '', $host);
            $scheme = $settings->remote_backup_ssl ? 'ftps' : 'ftp';
            $remoteName = $folder === '' ? basename($dumpPath) : $folder . '/' . basename($dumpPath);
            $remoteUrl = $scheme . '://' . $host . ':' . $port . '/' . str_replace('%2F', '/', rawurlencode($remoteName));

            $fp = fopen($dumpPath, 'rb');
            if ($fp === false) {
                throw new \RuntimeException('Unable to read the backup file.');
            }

            $curl = curl_init($remoteUrl);
            curl_setopt_array($curl, [
                CURLOPT_USERPWD => $username . ':' . $password,
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $fp,
                CURLOPT_INFILESIZE => filesize($dumpPath),
                CURLOPT_FTP_CREATE_MISSING_DIRS => 1,
                CURLOPT_FTP_USE_EPSV => true,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT => 1800,
                CURLOPT_RETURNTRANSFER => true,
            ]);

            if ($settings->remote_backup_ssl) {
                curl_setopt($curl, CURLOPT_USE_SSL, CURLUSESSL_ALL);
                curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
            }

            $result = curl_exec($curl);
            $error = curl_error($curl);
            curl_close($curl);
            fclose($fp);

            if ($result === false) {
                throw new \RuntimeException($error ?: 'Remote backup upload failed.');
            }

            $settings->update([
                'remote_backup_last_status' => 'success',
                'remote_backup_last_message' => 'Backup uploaded successfully.',
            ]);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $settings->update([
                'remote_backup_last_status' => 'failed',
                'remote_backup_last_message' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            $this->error($e->getMessage());
            return self::FAILURE;
        } finally {
            @unlink($dumpPath);
            @unlink($defaultsPath);
        }
    }
}
