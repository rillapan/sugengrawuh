<?php

namespace App\Console\Commands;

use App\Services\Hardware\TvAlertService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BillingTvAlertWorkerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:tv-alert-worker 
                            {--once : Run evaluation once and exit}
                            {--sleep=2 : Sleep interval in seconds}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Background worker for evaluating automatic 10-minute Samsung TV alerts and sweeping stale/expired TV events';

    /**
     * Execute the console command.
     *
     * @param TvAlertService $tvAlertService
     * @return int
     */
    public function handle(TvAlertService $tvAlertService): int
    {
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->error('Koneksi database gagal: ' . $e->getMessage());
            return Command::FAILURE;
        }

        if (!TvAlertService::hasTvControlSchema()) {
            $this->error('Tabel tv_control_events belum tersedia pada database.');
            return Command::FAILURE;
        }

        $once = (bool) $this->option('once');
        $sleepSeconds = max(1, (int) $this->option('sleep'));

        $this->info('Samsung TV Alert Worker started. Sleep interval: ' . $sleepSeconds . 's. Mode: ' . ($once ? 'Single-run' : 'Long-running daemon'));

        $running = true;
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () use (&$running) {
                $this->warn("\nReceived SIGTERM. Shutting down TV Alert Worker gracefully...");
                $running = false;
            });
            pcntl_signal(SIGINT, function () use (&$running) {
                $this->warn("\nReceived SIGINT. Shutting down TV Alert Worker gracefully...");
                $running = false;
            });
        }

        do {
            try {
                $now = Carbon::now();
                $result = $tvAlertService->evaluateAutoAlerts($now);

                if ($result['created_count'] > 0) {
                    $this->info("[{$now->toDateTimeString()}] Created {$result['created_count']} auto 10-min TV alert(s).");
                }

                $sweptCount = $result['expired_count'] + $result['cancelled_count'] + $result['stale_count'];
                if ($sweptCount > 0) {
                    $this->line("[{$now->toDateTimeString()}] Swept {$sweptCount} pending alert(s) (expired: {$result['expired_count']}, cancelled: {$result['cancelled_count']}, stale: {$result['stale_count']}).");
                }
            } catch (\Throwable $e) {
                $this->error('Fatal error in TV Alert Worker tick: ' . $e->getMessage());
                return Command::FAILURE;
            }

            if ($once) {
                break;
            }

            sleep($sleepSeconds);
        } while ($running);

        $this->info('Samsung TV Alert Worker stopped cleanly.');
        return Command::SUCCESS;
    }
}

