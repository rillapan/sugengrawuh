<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BillingReconcileCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:reconcile';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconcile billing sessions and station status after PC restart or crash';

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

        $this->info("Starting Billing & Station Reconciliation...");
        $now = Carbon::now();

        try {
            $result = $billingService->reconcileSessions($now);

            $this->table(
                ['Metric', 'Value'],
                [
                    ['Reconciliation Timestamp', $result['timestamp']],
                    ['Expired Sessions Transitioned', $result['expired_processed']],
                    ['Running Postpaid Sessions Retained', $result['postpaid_survived']],
                    ['Device Statuses Healed', $result['devices_healed']],
                ]
            );

            $this->info("Reconciliation completed successfully.");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Reconciliation failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
