<?php

namespace App\Console\Commands;

use App\Models\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class BackupRemote extends Command
{
    protected $signature = 'backup:remote {--force : Run immediately and ignore the configured interval}';
    protected $description = 'Create and upload a remote XPanel database backup over SSH/SFTP';

    public function handle(): int
    {
        $settings = Settings::first();

        if (!$settings || (!$settings->remote_backup_enabled && !$this->option('force'))) {
            return self::SUCCESS;
        }

        $interval = max(1, (int) $settings->remote_backup_interval_hours);
        if (!$this->option('force') &&
            $settings->remote_backup_last_at &&
            now()->lt($settings->remote_backup_last_at->copy()->addHours($interval))) {
            return self::SUCCESS;
        }

        $settings->update([
            'remote_backup_last_at' => now(),
            'remote_backup_last_status' => 'running',
            'remote_backup_last_message' => null,
        ]);

        $dumpPath = storage_path('app/remote-backup-' . now()->format('Ymd-His') . '.sql');
        $localBackupDir = storage_path('app/backup');
        $localBackupPath = $localBackupDir . '/' . basename($dumpPath);
        $defaultsPath = storage_path('app/.remote-backup-' . bin2hex(random_bytes(8)));

        try {
            if (!function_exists('ssh2_connect')) {
                throw new \RuntimeException('PHP SSH2 extension is not installed.');
            }

            $rawHost = trim((string) $settings->remote_backup_host);
            $host = $rawHost;
            $configuredPort = (int) $settings->remote_backup_port ?: 22;

            // Accept ssh://host, ssh://host:port and bracketed IPv6 values without
            // accidentally passing the scheme or port as part of the hostname.
            if (preg_match('#^ssh://#i', $rawHost)) {
                $parsed = parse_url($rawHost);
                if ($parsed === false || empty($parsed['host'])) {
                    throw new \RuntimeException('Invalid backup server address: ' . $rawHost);
                }
                $host = $parsed['host'];
                if (!empty($parsed['port'])) {
                    $configuredPort = (int) $parsed['port'];
                }
            } elseif (preg_match('/^\[([^\]]+)\](?::(\d+))?$/', $rawHost, $m)) {
                $host = $m[1];
                if (!empty($m[2])) {
                    $configuredPort = (int) $m[2];
                }
            }

            $host = trim($host, '/');
            $folder = trim((string) ($settings->remote_backup_folder ?? ''));
            $username = trim((string) $settings->remote_backup_username);
            $password = (string) $settings->remote_backup_password;
            $port = $configuredPort;

            if ($host === '' || $username === '' || $password === '') {
                throw new \RuntimeException('Remote backup settings are incomplete.');
            }

            if ($folder === '') {
                throw new \RuntimeException('Remote backup folder is required.');
            }

            if (str_contains($folder, '..')) {
                throw new \RuntimeException('Remote backup folder cannot contain "..".');
            }

            if ($port < 1 || $port > 65535) {
                throw new \RuntimeException('Remote backup SSH port is invalid.');
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

            if (!is_dir($localBackupDir) && !mkdir($localBackupDir, 0755, true) && !is_dir($localBackupDir)) {
                throw new \RuntimeException('Unable to create the local backup folder.');
            }

            if (!copy($dumpPath, $localBackupPath)) {
                throw new \RuntimeException('Unable to save the backup in the local backup list.');
            }

            // First check the actual TCP path. This turns the previous generic
            // "Unable to connect" message into a useful DNS/firewall/port error.
            $errno = 0;
            $errstr = '';
            $socket = @fsockopen($host, $port, $errno, $errstr, 8);
            if (!$socket) {
                $detail = trim($errstr) !== '' ? $errstr . ' (' . $errno . ')' : 'unknown socket error';
                throw new \RuntimeException(
                    "TCP connection to backup server {$host}:{$port} failed: {$detail}. Check DNS, firewall rules and that SSH is listening on this port."
                );
            }
            fclose($socket);

            $sshWarning = null;
            set_error_handler(function ($severity, $message) use (&$sshWarning) {
                $sshWarning = $message;
                return true;
            });

            try {
                $connection = ssh2_connect($host, $port);
            } finally {
                restore_error_handler();
            }

            if (!$connection) {
                $detail = $sshWarning ? ' ' . $sshWarning : '';
                throw new \RuntimeException(
                    "SSH handshake with {$host}:{$port} failed." . $detail
                );
            }

            if (function_exists('ssh2_set_timeout')) {
                ssh2_set_timeout($connection, 10);
            }

            if (!@ssh2_auth_password($connection, $username, $password)) {
                throw new \RuntimeException(
                    "SSH authentication failed for {$username}@{$host}:{$port}. The network connection is working, but the username/password was rejected."
                );
            }

            $sftp = @ssh2_sftp($connection);
            if (!$sftp) {
                throw new \RuntimeException(
                    "SSH login succeeded, but the SFTP subsystem could not be initialized on {$host}:{$port}."
                );
            }

            $home = @ssh2_sftp_realpath($sftp, '.');
            if ($folder[0] === '/') {
                $remoteFolder = rtrim($folder, '/');
            } else {
                if ($home === false || $home === '') {
                    throw new \RuntimeException('Unable to resolve the remote SSH home directory for the relative backup folder.');
                }
                $remoteFolder = rtrim($home, '/') . '/' . trim($folder, '/');
            }

            if (!is_dir('ssh2.sftp://' . intval($sftp) . $remoteFolder)) {
                if (!@ssh2_sftp_mkdir($sftp, $remoteFolder, 0755, true)) {
                    throw new \RuntimeException('Unable to create the remote backup folder: ' . $remoteFolder);
                }
            }

            $remoteFile = $remoteFolder . '/' . basename($dumpPath);
            $source = @fopen($dumpPath, 'rb');
            $target = @fopen('ssh2.sftp://' . intval($sftp) . $remoteFile, 'wb');

            if (!$source || !$target) {
                if (is_resource($source)) {
                    fclose($source);
                }
                if (is_resource($target)) {
                    fclose($target);
                }
                throw new \RuntimeException('Unable to open the SFTP backup destination: ' . $remoteFile);
            }

            $copied = stream_copy_to_stream($source, $target);
            fclose($source);
            fclose($target);

            $localSize = filesize($dumpPath);
            if ($copied === false || $localSize === false || (int) $copied !== (int) $localSize) {
                throw new \RuntimeException(
                    'Remote upload was incomplete. Expected ' . (int) $localSize . ' bytes, uploaded ' . (int) $copied . ' bytes.'
                );
            }

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
            @unlink($defaultsPath);
        }
    }
}
