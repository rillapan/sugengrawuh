<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BillingWorkerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:worker 
                            {--once : Run expiration evaluation once and exit}
                            {--sleep=2 : Sleep interval in seconds between ticks}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Background billing worker for evaluating expired prepaid and package sessions';

    /**
     * Execute the console command.
     *
     * @param BillingService $billingService
     * @return int
     */
    public function handle(BillingService $billingService): int
    {
        // 1. Fail-fast: verify database connectivity
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->error("Koneksi database gagal: " . $e->getMessage());
            return Command::FAILURE;
        }

        // 2. Fail-fast: verify complete G2 schema presence
        $missingColumns = Transaction::getMissingG2Columns();
        if (!empty($missingColumns)) {
            $this->error("Skema G2 tidak lengkap pada database. Kolom berikut tidak ditemukan: " . implode(', ', $missingColumns));
            return Command::FAILURE;
        }

        $once = (bool) $this->option('once');
        $sleepSeconds = max(1, (int) $this->option('sleep'));

        $this->info("Billing Worker started. Sleep interval: {$sleepSeconds}s. Mode: " . ($once ? 'Single-run' : 'Long-running daemon'));

        // Handle POSIX signals for graceful exit if available
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            $running = true;
            pcntl_signal(SIGTERM, function () use (&$running) {
                $this->warn("\nReceived SIGTERM. Shutting down Billing Worker gracefully...");
                $running = false;
            });
            pcntl_signal(SIGINT, function () use (&$running) {
                $this->warn("\nReceived SIGINT. Shutting down Billing Worker gracefully...");
                $running = false;
            });
        } else {
            $running = true;
        }

        do {
            try {
                $now = Carbon::now();
                $result = $billingService->evaluateExpiration($now);

                if (!empty($result['notice'])) {
                    $this->warn("[{$now->toDateTimeString()}] {$result['notice']}");
                }

                if ($result['count'] > 0) {
                    $this->info("[{$now->toDateTimeString()}] Processed {$result['count']} expired session(s).");
                    foreach ($result['processed'] as $item) {
                        $this->line("  -> Transaction #{$item['id_transaksi']} on Station #{$item['device_id']} expired at {$item['expires_at']}");
                    }
                }
            } catch (\Throwable $e) {
                $this->error("Fatal error in Billing Worker tick: " . $e->getMessage());
                return Command::FAILURE;
            }

            if ($once) {
                break;
            }

            sleep($sleepSeconds);
        } while ($running);

        $this->info("Billing Worker stopped cleanly.");
        return Command::SUCCESS;
    }
}
