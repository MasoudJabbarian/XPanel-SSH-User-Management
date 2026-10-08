<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Process\ProcessResult;
use Auth;
use Illuminate\Support\Facades\DB;


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
        if (!is_numeric($pid)) {
            abort(400, 'Not Valid Username');
        }
        Process::run(['sudo', '/usr/local/sbin/xpanel-userctl', 'kill-pid', (string) $pid]);
        return redirect()->back()->with('success', 'Killed');
    }

    public function kill_user(Request $request,$username)
    {
        $this->assertLinuxUsername($username);
        if (!is_string($username)) {
            abort(400, 'Not Valid Username');
        }
        Process::run(['sudo', '/usr/local/sbin/xpanel-userctl', 'kill-user', $username]);
        return redirect()->back()->with('success', 'Killed');
    }
    public function index()
    {
        $this->check();
        $duplicate = [];
        $data = [];
        $total = [];

        $list = Process::run(['sudo', '/usr/local/sbin/xpanel-userctl', 'online-port', (string) env('PORT_SSH', 22)]);
        $output = $list->output();
        $onlineuserlist = preg_split("/\r\n|\n|\r/", $output);
        return view('users.online', compact('data'));
    }
}
