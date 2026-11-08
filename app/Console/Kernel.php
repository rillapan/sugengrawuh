<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Periodic heartbeat for expiration check in case standalone daemon is briefly restarted
        $schedule->command('billing:worker --once')->everyMinute()->withoutOverlapping();
        // Periodic evaluation for Samsung TV 10-minute remaining alerts
        $schedule->command('billing:tv-alert-worker --once')->everyMinute()->withoutOverlapping();
        // Periodic reconciliation audit
        $schedule->command('billing:reconcile')->everyTenMinutes()->withoutOverlapping();
        // Daily automation pruning of expired intents and audit logs
        $schedule->command('billing:automation-prune')->dailyAt('04:00')->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
