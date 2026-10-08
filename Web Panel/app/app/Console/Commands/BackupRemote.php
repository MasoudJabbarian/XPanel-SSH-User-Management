<?php

namespace App\Console\Commands;

use App\Models\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class BackupRemote extends Command
{
    private const REMOTE_BACKUP_FOLDER = '/var/backups/xpanel';
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
            $folder = self::REMOTE_BACKUP_FOLDER;
            $username = trim((string) $settings->remote_backup_username);
            $password = (string) $settings->remote_backup_password;
            $port = $configuredPort;

            if ($host === '' || $username === '' || $password === '') {
                throw new \RuntimeException('Remote backup settings are incomplete.');
            }

            if ($folder === '') {
                throw new \RuntimeException('Remote backup folder is not configured.');
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
                throw new \RuntimeException('Unable to create the local backup folder. Run: sudo mkdir -p /var/www/html/app/storage/app/backup && sudo chown -R www-data:www-data /var/www/html/app/storage/app/backup');
            }

            // First check the actual TCP path. This turns the previous generic
            // "Unable to connect" message into a useful DNS/firewall/port error.
            $errno = 0;
            $errstr = '';
            $socket = @fsockopen($host, $port, $errno, $errstr, 8);
            if (!$socket) {
                $detail = trim($errstr) !== '' ? $errstr . ' (' . $errno . ')' : 'unknown socket error';
                $hint = "\n\nاگر SSH روی این پورت در سرور بکاپ در دسترس نیست، روی سرور بکاپ اجرا کنید:\n" .
                    "sudo sed -i -E 's/^#?Port .*/Port {$port}/' /etc/ssh/sshd_config\n" .
                    "sudo ufw allow {$port}/tcp 2>/dev/null || true\n" .
                    "sudo systemctl restart ssh\n" .
                    "سپس همین پورت {$port} را در XPanel وارد کنید.";
                throw new \RuntimeException(
                    "TCP connection to backup server {$host}:{$port} failed: {$detail}. Check DNS, firewall rules and that SSH is listening on this port." . $hint
                );
            }
            fclose($socket);

            // Do not use ssh2_auth_password() here. On some SSH/PAM/network
            // combinations the PHP SSH2 extension can block inside the C extension
            // and leave the backup status stuck at "running" indefinitely.
            // Use OpenSSH through sshpass with hard connection/process timeouts instead.
            if (!is_executable('/usr/bin/sshpass')) {
                throw new \\RuntimeException('The sshpass package is required for remote backups. Install it with: sudo apt-get install -y sshpass');
            }

            $sshOptions = [
                '-o', 'ConnectTimeout=8',
                '-o', 'ConnectionAttempts=1',
                '-o', 'ServerAliveInterval=5',
                '-o', 'ServerAliveCountMax=1',
                '-o', 'StrictHostKeyChecking=accept-new',
                '-p', (string) $port,
            ];

            $sshBase = array_merge([
                '/usr/bin/sshpass', '-e',
                '/usr/bin/ssh',
            ], $sshOptions);

            $mkdirCommand = 'mkdir -p -- ' . escapeshellarg($remoteFolder) . ' && test -d -- ' . escapeshellarg($remoteFolder);
            $mkdir = Process::env(['SSHPASS' => $password])
                ->timeout(20)
                ->run(array_merge($sshBase, [$username . '@' . $host, $mkdirCommand]));

            if ($mkdir->failed()) {
                $detail = trim($mkdir->errorOutput()) ?: trim($mkdir->output()) ?: 'unknown SSH error';
                throw new \\RuntimeException(
                    "Remote SSH command failed for {$username}@{$host}:{$port}: {$detail}"
                );
            }

            $remoteFile = $remoteFolder . '/' . basename($dumpPath);
            $scp = Process::env(['SSHPASS' => $password])
                ->timeout(60)
                ->run(array_merge([
                    '/usr/bin/sshpass', '-e',
                    '/usr/bin/scp',
                ], $sshOptions, [
                    $dumpPath,
                    $username . '@' . $host . ':' . $remoteFile,
                ]));

            if ($scp->failed()) {
                $detail = trim($scp->errorOutput()) ?: trim($scp->output()) ?: 'unknown SCP error';
                throw new \\RuntimeException(
                    "Remote backup upload failed for {$username}@{$host}:{$port}: {$detail}"
                );
            }

            $verify = Process::env(['SSHPASS' => $password])
                ->timeout(20)
                ->run(array_merge($sshBase, [
                    $username . '@' . $host,
                    'test -f ' . escapeshellarg($remoteFile) . ' && stat -c %s ' . escapeshellarg($remoteFile),
                ]));

            if ($verify->failed()) {
                $detail = trim($verify->errorOutput()) ?: trim($verify->output()) ?: 'remote file verification failed';
                throw new \\RuntimeException("Remote backup upload verification failed: {$detail}");
            }

            $remoteSize = (int) trim($verify->output());
            $localSize = filesize($dumpPath);
            if ($localSize === false || $remoteSize !== (int) $localSize) {
                throw new \\RuntimeException(
                    'Remote upload size mismatch. Expected ' . (int) $localSize . ' bytes, remote file is ' . $remoteSize . ' bytes.'
                );
            }

            if (!copy($dumpPath, $localBackupPath)) {
                // Remote upload already succeeded; keep the remote backup successful
                // but report the local archive problem clearly for the backup list.
                $localHint = 'Local backup archive could not be saved. Run: sudo mkdir -p /var/www/html/app/storage/app/backup && sudo chown -R www-data:www-data /var/www/html/app/storage/app/backup';
                $settings->update([
                    'remote_backup_last_status' => 'success',
                    'remote_backup_last_message' => 'Backup uploaded successfully via SFTP, but local backup-list copy failed. ' . $localHint,
                ]);
                $this->warn($localHint);
                return self::SUCCESS;
            }

            $settings->update([
                'remote_backup_last_status' => 'success',
                'remote_backup_last_message' => 'Backup uploaded successfully via SFTP and saved in the local backup list.',
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
