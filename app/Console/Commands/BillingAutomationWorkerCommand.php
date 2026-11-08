<?php

namespace App\Console\Commands;

use App\Models\AutomationIntent;
use App\Services\Hardware\Automation\AutomationDispatcherService;
use Carbon\Carbon;
use App\Services\Hardware\Automation\AutomationIntentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BillingAutomationWorkerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:automation-worker 
                            {--once : Run dispatch batch once and exit}
                            {--interval=500 : Polling interval in milliseconds}
                            {--batch=10 : Batch processing limit}
                            {--lease=60 : Stale lease recovery timeout in seconds}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Background IoT automation outbox worker for dispatching billing hardware intents';

    /**
     * Execute the console command.
     *
     * @param AutomationDispatcherService $dispatcherService
     * @return int
     */
    public function handle(AutomationDispatcherService $dispatcherService): int
    {
        // 1. Fail-fast: verify database connectivity
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->error("Koneksi database gagal: " . $e->getMessage());
            return Command::FAILURE;
        }

        // 2. Fail-fast: verify schema presence
        if (!AutomationIntentService::hasG5Schema()) {
            $this->error("Skema otomatisasi (automation_intents / automation_execution_logs) belum diterapkan.");
            return Command::FAILURE;
        }

        $once = (bool) $this->option('once');
        $intervalMs = max(100, (int) $this->option('interval'));
        $batchLimit = max(1, (int) $this->option('batch'));
        $leaseSeconds = max(10, (int) $this->option('lease'));
        $workerId = getmypid() . '@' . gethostname();

        $this->info("Billing Automation Worker started. Worker ID: {$workerId}. Polling interval: {$intervalMs}ms. Mode: " . ($once ? 'Single-run' : 'Long-running daemon'));

        // Startup Crash Recovery: Recover any stale locked intents
        $recoveredOnBoot = $dispatcherService->recoverStaleLockedIntents($leaseSeconds);
        if ($recoveredOnBoot > 0) {
            $this->warn("Recovered {$recoveredOnBoot} stale locked intent(s) on worker startup.");
        }

        // Signal handling for graceful exit
        $running = true;
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () use (&$running) {
                $this->warn("\nReceived SIGTERM. Shutting down Automation Worker gracefully...");
                $running = false;
            });
            pcntl_signal(SIGINT, function () use (&$running) {
                $this->warn("\nReceived SIGINT. Shutting down Automation Worker gracefully...");
                $running = false;
            });
        }

        $lastStaleCheck = time();

        do {
            try {
                $now = Carbon::now();

                // Periodic stale lease check every 60 seconds
                if (time() - $lastStaleCheck >= 60) {
                    $recovered = $dispatcherService->recoverStaleLockedIntents($leaseSeconds, $now);
                    if ($recovered > 0) {
                        $this->warn("[{$now->toDateTimeString()}] Recovered {$recovered} stale locked intent(s).");
                    }
                    $lastStaleCheck = time();
                }

                $dispatchedCount = $dispatcherService->dispatchBatch($batchLimit, $now, $workerId);

                if ($dispatchedCount > 0) {
                    $this->info("[{$now->toDateTimeString()}] Processed {$dispatchedCount} automation intent(s).");
                }
            } catch (\Throwable $e) {
                $this->error("Fatal error in Automation Worker tick: " . $e->getMessage());
                return Command::FAILURE;
            }

            if ($once) {
                break;
            }

            usleep($intervalMs * 1000);
        } while ($running);

        $this->info("Billing Automation Worker stopped cleanly.");
        return Command::SUCCESS;
    }
}
