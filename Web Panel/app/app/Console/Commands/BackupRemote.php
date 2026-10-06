<?php

namespace App\Console\Commands;

use App\Models\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class BackupRemote extends Command
{
    protected $signature = 'backup:remote';
    protected $description = 'Create and upload a remote XPanel database backup over SSH/SFTP';

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
        $archivePath = $dumpPath . '.gz';
        $defaultsPath = storage_path('app/.remote-backup-' . bin2hex(random_bytes(8)));

        try {
            if (!function_exists('ssh2_connect')) {
                throw new \RuntimeException('PHP SSH2 extension is not installed.');
            }

            $host = trim((string) $settings->remote_backup_host);
            $host = preg_replace('#^ssh://#i', '', $host);
            $host = trim($host, '/');
            $folder = trim((string) ($settings->remote_backup_folder ?? ''));
            $username = trim((string) $settings->remote_backup_username);
            $password = (string) $settings->remote_backup_password;
            $port = (int) $settings->remote_backup_port ?: 22;

            if ($host === '' || $username === '' || $password === '') {
                throw new \RuntimeException('Remote backup settings are incomplete.');
            }

            if ($folder === '') {
                throw new \RuntimeException('Remote backup folder is required.');
            }

            if (str_contains($folder, '..')) {
                throw new \RuntimeException('Remote backup folder cannot contain "..".');
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

            if (file_put_contents($dumpPath, $dump->output()) === false) {
                throw new \RuntimeException('Unable to create the database backup file.');
            }

            $gzip = Process::run(['/usr/bin/gzip', '-f', $dumpPath]);
            if ($gzip->failed() || !is_file($archivePath)) {
                throw new \RuntimeException(trim($gzip->errorOutput()) ?: 'Unable to compress the database backup.');
            }

            $connection = @ssh2_connect($host, $port);
            if (!$connection) {
                throw new \RuntimeException('Unable to connect to the backup server over SSH.');
            }

            if (!@ssh2_auth_password($connection, $username, $password)) {
                throw new \RuntimeException('SSH authentication failed for the backup server.');
            }

            $sftp = @ssh2_sftp($connection);
            if (!$sftp) {
                throw new \RuntimeException('Unable to initialize the SFTP subsystem.');
            }

            $remoteFolder = $folder[0] === '/'
                ? rtrim($folder, '/')
                : rtrim(ssh2_sftp_realpath($sftp, '.'), '/') . '/' . trim($folder, '/');

            if (!is_dir('ssh2.sftp://' . intval($sftp) . $remoteFolder)) {
                if (!@ssh2_sftp_mkdir($sftp, $remoteFolder, 0755, true)) {
                    throw new \RuntimeException('Unable to create the remote backup folder.');
                }
            }

            $remoteFile = $remoteFolder . '/' . basename($archivePath);
            $source = @fopen($archivePath, 'rb');
            $target = @fopen('ssh2.sftp://' . intval($sftp) . $remoteFile, 'wb');

            if (!$source || !$target) {
                if (is_resource($source)) {
                    fclose($source);
                }
                if (is_resource($target)) {
                    fclose($target);
                }
                throw new \RuntimeException('Unable to open the SFTP backup destination.');
            }

            stream_copy_to_stream($source, $target);
            fclose($source);
            fclose($target);

            $settings->update([
                'remote_backup_last_status' => 'success',
                'remote_backup_last_message' => 'Backup uploaded successfully via SFTP.',
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
            @unlink($archivePath);
            @unlink($defaultsPath);
        }
    }
}
