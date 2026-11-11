<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\BillingService;
use App\Services\Hardware\Automation\AutomationDispatcherService;
use App\Services\Hardware\Automation\AutomationIntentService;
use App\Services\Hardware\TvAlertService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BillingDaemonCommand extends Command
{
    public const HEARTBEAT_CACHE_KEY = 'billing:daemon:heartbeat';
    public const HEARTBEAT_TTL_SECONDS = 15;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:daemon
                            {--once : Run single cycle and exit}
                            {--interval=1000 : Polling loop interval in milliseconds}
                            {--lease=60 : Stale lease recovery timeout in seconds}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Unified background billing daemon: evaluates expired sessions, dispatches IoT hardware intents, and processes TV alerts';

    /**
     * Execute the console command.
     */
    public function handle(
        BillingService $billingService,
        AutomationDispatcherService $dispatcherService,
        TvAlertService $tvAlertService
    ): int {
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->error("Koneksi database gagal: " . $e->getMessage());
            return Command::FAILURE;
        }

        $once = (bool) $this->option('once');
        $intervalMs = max(200, (int) $this->option('interval'));
        $leaseSeconds = max(10, (int) $this->option('lease'));
        $workerId = getmypid() . '@' . gethostname();

        $this->info("Unified Billing Daemon started [Worker ID: {$workerId}]. Interval: {$intervalMs}ms. Mode: " . ($once ? 'Single-run' : 'Continuous daemon'));

        // Startup Crash Recovery
        if (AutomationIntentService::hasG5Schema()) {
            $recovered = $dispatcherService->recoverStaleLockedIntents($leaseSeconds);
            if ($recovered > 0) {
                $this->warn("Recovered {$recovered} stale locked intent(s) on startup.");
            }
        }

        $running = true;
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () use (&$running) {
                $this->warn("\nReceived SIGTERM. Shutting down Billing Daemon gracefully...");
                $running = false;
            });
            pcntl_signal(SIGINT, function () use (&$running) {
                $this->warn("\nReceived SIGINT. Shutting down Billing Daemon gracefully...");
                $running = false;
            });
        }

        $lastAlertCheck = 0;
        $lastStaleCheck = time();

        do {
            try {
                $now = Carbon::now();

                try {
                    \Illuminate\Support\Facades\Cache::put(self::HEARTBEAT_CACHE_KEY, [
                        'worker_id' => $workerId,
                        'timestamp' => $now->timestamp,
                        'updated_at' => $now->toIso8601String(),
                    ], self::HEARTBEAT_TTL_SECONDS);
                } catch (\Throwable $cacheErr) {
                    // Ignore cache write errors so daemon loop never halts
                }

                // 1. Evaluate expired sessions
                if (Transaction::hasG2Schema()) {
                    $result = $billingService->evaluateExpiration($now);
                    if ($result['count'] > 0) {
                        $this->info("[{$now->toDateTimeString()}] Expired {$result['count']} session(s).");
                        foreach ($result['processed'] as $item) {
                            $this->line("  -> Session #{$item['id_transaksi']} on Station #{$item['device_id']} expired at {$item['expires_at']}");
                        }
                    }
                }

                // 2. Dispatch hardware intents (turning off expired stations or turning on new ones)
                if (AutomationIntentService::hasG5Schema() && app(AutomationIntentService::class)->isAutomationEnabled()) {
                    // Periodic stale recovery every 60s
                    if (time() - $lastStaleCheck >= 60) {
                        $recovered = $dispatcherService->recoverStaleLockedIntents($leaseSeconds, $now);
                        if ($recovered > 0) {
                            $this->warn("[{$now->toDateTimeString()}] Recovered {$recovered} stale locked intent(s).");
                        }
                        $lastStaleCheck = time();
                    }

                    $dispatched = $dispatcherService->dispatchBatch(10, $now, $workerId);
                    if ($dispatched > 0) {
                        $this->info("[{$now->toDateTimeString()}] Dispatched {$dispatched} hardware intent(s).");
                    }
                }

                // 3. Evaluate 10-minute TV alerts (throttled to every 30 seconds)
                if (TvAlertService::hasTvControlSchema() && (time() - $lastAlertCheck >= 30)) {
                    $tvAlertService->evaluateAutoAlerts();
                    $lastAlertCheck = time();
                }
            } catch (\Throwable $e) {
                $this->error("Error in daemon cycle: " . $e->getMessage());
            }

            if ($once) {
                break;
            }

            usleep($intervalMs * 1000);
        } while ($running);

        if (!$once) {
            try {
                \Illuminate\Support\Facades\Cache::forget(self::HEARTBEAT_CACHE_KEY);
            } catch (\Throwable $cacheErr) {
                // Ignore cache errors on shutdown
            }
        }

        $this->info("Unified Billing Daemon stopped cleanly.");
        return Command::SUCCESS;
    }
}

