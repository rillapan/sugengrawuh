<?php

namespace App\Console\Commands;

use App\Models\FnbOrder;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpirePendingFnbOrdersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fnb:expire-pending';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire PENDING F&B orders older than 30 minutes without modifying stock';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $cutoff = Carbon::now()->subMinutes(30);

        $candidateIds = FnbOrder::where('status', FnbOrder::STATUS_PENDING)
            ->where('created_at', '<=', $cutoff)
            ->pluck('id');

        $expiredCount = 0;

        foreach ($candidateIds as $orderId) {
            DB::transaction(function () use ($orderId, &$expiredCount) {
                $order = FnbOrder::where('id', $orderId)
                    ->lockForUpdate()
                    ->first();

                if ($order && $order->status === FnbOrder::STATUS_PENDING) {
                    $order->status = FnbOrder::STATUS_EXPIRED;
                    $order->save();
                    $expiredCount++;
                }
            });
        }

        $this->info("Expired {$expiredCount} pending F&B order(s).");

        return 0;
    }
}
