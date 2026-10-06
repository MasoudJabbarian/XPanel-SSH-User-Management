<?php

namespace App\Console;

use App\Models\Users;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Process;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        $schedule->call(function (): void {
            Users::where('status', 'active')
                ->whereNotNull('end_date')
                ->whereDate('end_date', '<=', now()->toDateString())
                ->each(function (Users $user): void {
                    Process::run(['sudo', '/usr/local/sbin/xpanel-userctl', 'delete', $user->username]);
                    $user->update(['status' => 'expired']);
                });
        })->everyMinute()->withoutOverlapping(2);
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}
