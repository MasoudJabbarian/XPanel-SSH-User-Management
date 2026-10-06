<?php

namespace App\Http\Controllers;

use App\Models\Traffic;
use App\Models\Users;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Process;

class DahboardController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:admins');
    }

    private function usersQuery()
    {
        $admin = Auth::user();
        $query = Users::query();
        return $admin->permission === 'admin'
            ? $query
            : $query->where('customer_user', $admin->username);
    }

    public function index()
    {
        $query = $this->usersQuery();
        $alluser = (clone $query)->count();
        $active_user = (clone $query)->where('status','active')->count();
        $deactive_user = (clone $query)->where('status','deactive')->count();
        $expired_user = (clone $query)->where('status','expired')->count();
        $traffic_user = (clone $query)->where('status','traffic')->count();

        $onlineOutput = Process::run(['sudo','/usr/local/sbin/xpanel-userctl','online-port',(string)env('PORT_SSH',22)])->output();
        $online_user = count(array_filter(preg_split('/\R/', trim($onlineOutput))));

        $traffic_total = Traffic::whereIn('username', (clone $query)->pluck('username'))->sum('total');
        $total = $alluser;

        return view('dashboard.home', compact(
            'alluser','active_user','expired_user','traffic_user',
            'deactive_user','online_user','traffic_total','total'
        ));
    }

    public function usage()
    {
        $load = sys_getloadavg();
        $cpu_free = (int) round(($load[0] ?? 0) * 100);
        $ram_free = 0;
        if (is_file('/proc/meminfo')) {
            $mem = file_get_contents('/proc/meminfo');
            preg_match('/MemTotal:\s+(\d+)/', $mem, $total);
            preg_match('/MemAvailable:\s+(\d+)/', $mem, $available);
            if (!empty($total[1])) {
                $ram_free = (int) round((1 - ((int)($available[1] ?? 0) / (int)$total[1])) * 100);
            }
        }
        return response()->json(['cpuLoad'=>$cpu_free,'ramUsage'=>$ram_free]);
    }
}
