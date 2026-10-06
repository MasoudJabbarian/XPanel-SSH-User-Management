<?php

namespace App\Console\Commands;

use App\Models\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Process;

class BackupRemote extends Command
{
    protected $signature = 'backup:remote {--force : Run even when the configured interval has not elapsed}';
    protected $description = 'Create a database backup and upload it to the configured FTP/FTPS server';

    public function handle(): int
    {
        $settings = Settings::first();

        if (!$settings || !$settings->remote_backup_enabled) {
            return self::SUCCESS;
        }

        $interval = max(1, (int) $settings->remote_backup_interval_hours);
        if (!$this->option('force') && $settings->remote_backup_last_at) {
            $nextRun = $settings->remote_backup_last_at->copy()->addHours($interval);
            if (now()->lt($nextRun)) {
                return self::SUCCESS;
            }
        }

        $settings->update([
            'remote_backup_last_at' => now(),
            'remote_backup_last_status' => 'running',
            'remote_backup_last_message' => null,
        ]);

        $dumpPath = storage_path('app/remote-backup-' . now()->format('Ymd-His') . '.sql');
        $defaultsPath = storage_path('app/.remote-backup-mysql-' . bin2hex(random_bytes(6)));

        try {
            $password = Crypt::decryptString($settings->remote_backup_password ?? '');

            file_put_contents($defaultsPath, implode(PHP_EOL, [
                '[client]',
                'host=' . env('DB_HOST', '127.0.0.1'),
                'port=' . env('DB_PORT', '3306'),
                'user=' . env('DB_USERNAME'),
                'password=' . $password,
                '',
            ]));
            chmod($defaultsPath, 0600);

            $database = env('DB_DATABASE');
            if (!$database) {
                throw new \RuntimeException('Database name is not configured.');
            }

            $result = Process::run([
                '/usr/bin/mysqldump',
                '--defaults-extra-file=' . $defaultsPath,
                '--single-transaction',
                '--quick',
                '--routines',
                '--triggers',
                $database,
            ]);

            if ($result->failed()) {
                throw new \RuntimeException(trim($result->errorOutput()) ?: 'mysqldump failed.');
            }

            file_put_contents($dumpPath, $result->output());

            $host = trim((string) $settings->remote_backup_host);
            $folder = trim((string) ($settings->remote_backup_folder ?? ''), '/');
            $username = (string) $settings->remote_backup_username;
            $port = (int) $settings->remote_backup_port;
            $filename = basename($dumpPath);

            if ($host === '' || $username === '' || $settings->remote_backup_password === null) {
                throw new \RuntimeException('Remote backup connection settings are incomplete.');
            }

            $host = preg_replace('#^ftps?://#i', '', $host);
            $scheme = $settings->remote_backup_ssl ? 'ftps' : 'ftp';
            $remotePath = $folder === '' ? $filename : $folder . '/' . $filename;
            $remoteUrl = $scheme . '://' . $host . ':' . $port . '/' . str_replace('%2F', '/', rawurlencode($remotePath));

            $fp = fopen($dumpPath, 'rb');
            if ($fp === false) {
                throw new \RuntimeException('Unable to open the backup file.');
            }

            $ch = curl_init($remoteUrl);
            curl_setopt_array($ch, [
                CURLOPT_USERPWD => $username . ':' . $password,
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $fp,
                CURLOPT_INFILESIZE => filesize($dumpPath),
                CURLOPT_FTP_CREATE_MISSING_DIRS => defined('CURLFTP_CREATE_DIR_RETRY') ? CURLFTP_CREATE_DIR_RETRY : 2,
                CURLOPT_FTP_USE_EPSV => true,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT => 1800,
                CURLOPT_RETURNTRANSFER => true,
            ]);

            if ($settings->remote_backup_ssl) {
                curl_setopt($ch, CURLOPT_USE_SSL, CURLUSESSL_ALL);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            }

            $ok = curl_exec($ch);
            $error = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            fclose($fp);

            if ($ok === false) {
                throw new \RuntimeException($error ?: 'FTP upload failed (code ' . $httpCode . ').');
            }

            $settings->update([
                'remote_backup_last_status' => 'success',
                'remote_backup_last_message' => 'Backup uploaded successfully.',
            ]);

            @unlink($dumpPath);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $settings->update([
                'remote_backup_last_status' => 'failed',
                'remote_backup_last_message' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            @unlink($dumpPath);
            $this->error($e->getMessage());
            return self::FAILURE;
        } finally {
            @unlink($defaultsPath);
        }
    }
}
