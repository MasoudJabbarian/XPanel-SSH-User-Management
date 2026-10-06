<?php

namespace App\Http\Controllers;

use App\Models\Admins;
use App\Models\Api;
use App\Models\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Response;

class SettingsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:admins');
    }

    private function check(): void
    {
        $user = Auth::user();
        if ($user && $user->permission === 'reseller') {
            abort(403);
        }
    }

    private function safeBackupName(string $name): string
    {
        if ($name !== basename($name) || !preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
            abort(422, 'Invalid backup filename');
        }
        return $name;
    }

    public function defualt()
    {
        $this->check();
        return redirect()->route('settings', ['name' => 'general']);
    }

    public function mod(Request $request, string $name)
    {
        $this->check();
        if (in_array($name, ['night', 'light'], true)) {
            $this->setEnvValue('APP_MODE', $name);
        }
        return back()->with('success', 'success');
    }

    public function lang(Request $request, string $name)
    {
        $this->check();
        if (in_array($name, ['fa', 'en', 'ru'], true)) {
            $this->setEnvValue('APP_LOCALE', $name);
        }
        return back()->with('success', 'success');
    }

    private function setEnvValue(string $key, string $value): void
    {
        $path = base_path('.env');
        $contents = file_exists($path) ? file_get_contents($path) : '';
        $line = $key . '=' . str_replace(["\r", "\n"], '', $value);
        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';
        $contents = preg_match($pattern, $contents)
            ? preg_replace($pattern, $line, $contents)
            : rtrim($contents, "\r\n") . "\n" . $line . "\n";
        file_put_contents($path, $contents, LOCK_EX);
    }

    public function index(Request $request, string $name)
    {
        $this->check();
        return match ($name) {
            'general' => $this->generalView(),
            'backup' => $this->backupView(),
            'api' => view('settings.api', ['apis' => Api::orderByDesc('id')->get()]),
            default => abort(404),
        };
    }

    private function generalView()
    {
        $setting = Settings::first();
        $status = $setting?->multiuser ?? 'deactive';
        $traffic_base = env('TRAFFIC_BASE', 12);
        return view('settings.general', compact('traffic_base', 'status'));
    }

    private function backupView()
    {
        $dir = storage_path('backup');
        $lists = is_dir($dir) ? array_values(array_diff(scandir($dir), ['.', '..'])) : [];
        return view('settings.backup', compact('lists'));
    }

    public function update_general(Request $request)
    {
        $this->check();
        $request->validate([
            'status_multiuser' => 'nullable|in:active,deactive',
            'status_log' => 'nullable|in:active,deactive',
            'anti_user' => 'nullable|in:active,deactive',
            'status_traffic' => 'nullable|in:active,deactive',
            'status_day' => 'nullable|in:active,deactive',
            'traffic_base' => 'nullable|numeric|min:0',
        ]);

        $statusMulti = $request->input('status_multiuser', 'deactive');
        $statusLog = $request->input('status_log', 'deactive');
        $antiUser = $request->input('anti_user', 'active');
        $statusTraffic = $request->input('status_traffic', 'active');
        $statusDay = $request->input('status_day', 'deactive');

        $this->setEnvValue('ANTI_USER', $antiUser);
        $this->setEnvValue('STATUS_LOG', $statusLog);
        $this->setEnvValue('CRON_TRAFFIC', $statusTraffic);
        $this->setEnvValue('DAY', $statusDay);

        if ($request->filled('traffic_base')) {
            $this->setEnvValue('TRAFFIC_BASE', (string) $request->traffic_base);
        }

        Settings::updateOrCreate(
            ['id' => 1],
            ['multiuser' => $statusMulti]
        );

        return redirect()->route('settings', ['name' => 'general'])->with('success', 'success');
    }

    public function change_port_ssh(Request $request)
    {
        $this->check();
        $validated = $request->validate(['port' => 'required|integer|min:1|max:65535']);
        $result = Process::run(['sudo', '/usr/local/sbin/xpanel-userctl', 'ssh-port', (string) $validated['port']]);
        if (!$result->successful()) {
            return back()->with('alert', 'SSH configuration validation failed.');
        }
        $this->setEnvValue('PORT_SSH', (string) $validated['port']);
        return back()->with('success', 'SSH port changed.');
    }

    public function upload_backup(Request $request)
    {
        $this->check();
        $request->validate(['file' => 'required|file|max:51200']);
        $file = $request->file('file');
        $name = $this->safeBackupName($file->getClientOriginalName());
        if (!preg_match('/\.(sql|sql\.gz)$/i', $name)) {
            abort(422, 'Only SQL backups are supported');
        }
        $file->move(storage_path('backup'), $name);
        return back()->with('success', 'Backup uploaded.');
    }

    public function delete_backup(string $name)
    {
        $this->check();
        $name = $this->safeBackupName($name);
        $path = storage_path('backup/' . $name);
        if (is_file($path)) {
            unlink($path);
        }
        return back()->with('success', 'Backup deleted.');
    }

    public function make_backup()
    {
        $this->check();
        $result = Process::run(['sudo', '/usr/local/sbin/xpanel-userctl', 'db-backup']);
        return $result->successful()
            ? back()->with('success', 'Backup created.')
            : back()->with('alert', 'Backup failed.');
    }

    public function restore_backup(string $name)
    {
        $this->check();
        $name = $this->safeBackupName($name);
        $result = Process::run(['sudo', '/usr/local/sbin/xpanel-userctl', 'db-restore', $name]);
        return $result->successful()
            ? back()->with('success', 'Backup restored.')
            : back()->with('alert', 'Restore failed.');
    }

    public function download_backup(string $name)
    {
        $this->check();
        $name = $this->safeBackupName($name);
        $path = storage_path('backup/' . $name);
        abort_unless(is_file($path), 404);
        return Response::download($path, $name);
    }

    public function insert_api(Request $request)
    {
        $this->check();
        $request->validate([
            'description' => 'nullable|string|max:255',
            'allow_ip' => 'nullable|ip',
            'description' => 'nullable|string|max:255',
        ]);
        $token = bin2hex(random_bytes(32));
        Api::create([
            'username' => Auth::user()->username,
            'token' => $token,
            'allow_ip' => $request->allow_ip ?: '0.0.0.0/0',
            'description' => $request->description ?: 'SSH API',
            'status' => 'active',
        ]);
        return back()->with('success', 'API token created.');
    }

    public function renew_api(int $id)
    {
        $this->check();
        Api::whereKey($id)->update(['token' => bin2hex(random_bytes(32))]);
        return back()->with('success', 'API token renewed.');
    }

    public function delete_api(int $id)
    {
        $this->check();
        Api::whereKey($id)->delete();
        return back()->with('success', 'API token deleted.');
    }
}
