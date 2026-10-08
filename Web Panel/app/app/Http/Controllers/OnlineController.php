<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Auth;


class OnlineController extends Controller
{
    private function assertLinuxUsername(string $username): void
    {
        if (!preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $username)) {
            abort(422, 'Invalid Linux username');
        }
    }
    public function __construct() {
        $this->middleware('auth:admins');

    }
    public function check()
    {
        $user = Auth::user();
        if($user->permission=='reseller')
        {
            exit(view('access'));
        }
    }
    public function kill_pid(Request $request,$pid)
    {
        if (!ctype_digit((string) $pid)) {
            abort(422, 'Invalid PID');
        }
        Process::run(['sudo', '/usr/local/sbin/xpanel-userctl', 'kill-pid', (string) $pid]);
        return redirect()->back()->with('success', 'Killed');
    }

    public function kill_user(Request $request,$username)
    {
        $this->assertLinuxUsername($username);
        Process::run(['sudo', '/usr/local/sbin/xpanel-userctl', 'kill-user', $username]);
        return redirect()->back()->with('success', 'Killed');
    }
    public function index()
    {
        $this->check();
        $data = [];
        $seen = [];

        $result = Process::run([
            'sudo',
            '/usr/local/sbin/xpanel-userctl',
            'online-port',
            (string) env('PORT_SSH', 22),
        ]);

        foreach (preg_split("/\\r\\n|\\n|\\r/", trim($result->output())) as $line) {
            $fields = preg_split('/\\s+/', trim($line));
            if (count($fields) < 9) {
                continue;
            }

            $username = $fields[2] ?? '';
            $pid = $fields[1] ?? '';
            if ($username === '' || in_array($username, ['root', 'sshd'], true) || !ctype_digit($pid)) {
                continue;
            }

            $connection = $fields[count($fields) - 1] ?? '';
            $ip = '';
            if (str_contains($connection, '->')) {
                $remote = substr($connection, strrpos($connection, '->') + 2);
                $remote = preg_replace('/:\\d+$/', '', $remote);
                $ip = trim($remote, '[]');
            }

            $color = isset($seen[$username]) ? '#dc2626' : '#269393';
            $seen[$username] = true;

            $data[] = [
                'username' => $username,
                'color' => $color,
                'ip' => $ip,
                'pid' => $pid,
                'protocol' => 'SSH',
            ];
        }

        return view('users.online', compact('data'));
    }
}
