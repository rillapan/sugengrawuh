<?php

namespace App\Console\Commands;

use App\Models\AutomationExecutionLog;
use App\Models\AutomationIntent;
use App\Services\Hardware\Automation\AutomationIntentService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BillingAutomationPruneCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:automation-prune';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deterministic pruning of automation intents (30d) and execution logs (30d success, 90d failed)';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        if (!AutomationIntentService::hasG5Schema()) {
            $this->info("Skema otomatisasi (automation_intents) belum diterapkan. Pruning dilewati.");
            return Command::SUCCESS;
        }

        $now = Carbon::now();
        $this->info("Starting automation pruning as of {$now->toDateTimeString()}...");

        // 1. Prune successful execution logs (30 days)
        $successCutoff = (clone $now)->subDays(30);
        $prunedSuccessLogs = AutomationExecutionLog::where('execution_status', AutomationExecutionLog::STATUS_SUCCESS)
            ->where('executed_at', '<', $successCutoff)
            ->delete();
        $this->line("  -> Pruned {$prunedSuccessLogs} successful execution logs older than 30 days.");

        // 2. Prune failed / retry execution logs (90 days)
        $failedCutoff = (clone $now)->subDays(90);
        $prunedFailedLogs = AutomationExecutionLog::whereIn('execution_status', [
                AutomationExecutionLog::STATUS_FAILED,
                AutomationExecutionLog::STATUS_RETRY_SCHEDULED,
                AutomationExecutionLog::STATUS_SKIPPED,
            ])
            ->where('executed_at', '<', $failedCutoff)
            ->delete();
        $this->line("  -> Pruned {$prunedFailedLogs} failed/skipped execution logs older than 90 days.");

        // 3. Prune inactive / completed intents (30 days)
        // Because fk_auto_logs_intent has ON DELETE SET NULL, deleting intents does NOT delete the 90-day failed logs!
        $prunedCompletedIntents = AutomationIntent::whereIn('status', [
                AutomationIntent::STATUS_COMPLETED,
                AutomationIntent::STATUS_CANCELLED,
                AutomationIntent::STATUS_STALE,
                AutomationIntent::STATUS_SKIPPED,
            ])
            ->where('updated_at', '<', $successCutoff)
            ->delete();
        $this->line("  -> Pruned {$prunedCompletedIntents} completed/inactive intents older than 30 days.");

        // 4. Prune failed intents (90 days)
        // Note: PENDING, PROCESSING, and RETRY_PENDING intents are STRICTLY NEVER PRUNED!
        $prunedFailedIntents = AutomationIntent::where('status', AutomationIntent::STATUS_FAILED)
            ->where('updated_at', '<', $failedCutoff)
            ->delete();
        $this->line("  -> Pruned {$prunedFailedIntents} failed intents older than 90 days.");

        $this->info("Automation pruning complete.");
        return Command::SUCCESS;
    }
}
