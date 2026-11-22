<?php

namespace App\Services\Finance;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Playstation;
use App\Models\Transaction;
use App\Models\FnbOrder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CashierRevenueService
{
    public const TIMEZONE = 'Asia/Jakarta';

    public const CHANNEL_BILLING = 'BILLING';
    public const CHANNEL_FNB = 'FNB';
    public const CHANNEL_ADMIN = 'ADMIN';
    public const CHANNEL_UNKNOWN = 'UNKNOWN';

    public const CATEGORY_PLAYSTATION = 'PLAYSTATION';
    public const CATEGORY_BILLIARD = 'BILLIARD';
    public const CATEGORY_CAFE = 'CAFE';

    /**
     * Resolve start and end Carbon instances in Asia/Jakarta timezone.
     */
    public function resolveDateRange(?Carbon $start = null, ?Carbon $end = null): array
    {
        $startDate = $start
            ? $start->copy()->setTimezone(self::TIMEZONE)->startOfDay()
            : Carbon::now(self::TIMEZONE)->startOfDay();

        $endDate = $end
            ? $end->copy()->setTimezone(self::TIMEZONE)->endOfDay()
            : Carbon::now(self::TIMEZONE)->endOfDay();

        return [$startDate, $endDate];
    }

    /**
     * Determine cashier operational channel from user attributes.
     */
    public function getCashierChannel(?User $user): string
    {
        if (!$user) {
            return self::CHANNEL_UNKNOWN;
        }

        if ($user->role === 'kasir') {
            return (bool) $user->is_fnb_staff ? self::CHANNEL_FNB : self::CHANNEL_BILLING;
        }

        if (in_array($user->role, ['admin', 'owner'], true)) {
            return self::CHANNEL_ADMIN;
        }

        return self::CHANNEL_UNKNOWN;
    }

    /**
     * Base query for paid invoices within a date range and optional cashier filter.
     */
    protected function baseInvoiceQuery(Carbon $start, Carbon $end, ?int $cashierUserId = null): Builder
    {
        $query = Invoice::query()
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$start, $end]);

        if ($cashierUserId !== null) {
            $query->where('cashier_user_id', $cashierUserId);
        }

        return $query;
    }

    /**
     * Summary of revenue for an individual cashier or all cashiers.
     *
     * @param Carbon|null $start
     * @param Carbon|null $end
     * @param int|null $cashierUserId
     * @return array
     */
    public function getCashierRevenueSummary(?Carbon $start = null, ?Carbon $end = null, ?int $cashierUserId = null): array
    {
        [$startDate, $endDate] = $this->resolveDateRange($start, $end);

        $query = $this->baseInvoiceQuery($startDate, $endDate, $cashierUserId);

        $totalNet = (float) $query->sum('final_total');
        $totalGross = (float) $query->sum('subtotal');
        $totalDiscount = (float) $query->sum('discount');
        $invoiceCount = (int) $query->count();

        // Payment method breakdown
        $tunai = (float) (clone $query)->where('payment_method', 'tunai')->sum('final_total');
        $ewallet = (float) (clone $query)->where('payment_method', 'e-wallet')->sum('final_total');
        $transfer = (float) (clone $query)->where('payment_method', 'transfer_bank')->sum('final_total');
        $other = (float) (clone $query)->whereNotIn('payment_method', ['tunai', 'e-wallet', 'transfer_bank'])->sum('final_total');

        $cashierUser = $cashierUserId ? User::find($cashierUserId) : null;

        return [
            'cashier_user_id' => $cashierUserId,
            'cashier_name' => $cashierUser ? $cashierUser->name : null,
            'channel' => $cashierUser ? $this->getCashierChannel($cashierUser) : null,
            'total_net' => $totalNet,
            'total_gross' => $totalGross,
            'total_discount' => $totalDiscount,
            'invoice_count' => $invoiceCount,
            'payment_methods' => [
                'tunai' => $tunai,
                'e-wallet' => $ewallet,
                'transfer_bank' => $transfer,
                'other' => $other,
            ],
            'start_date' => $startDate->toDateTimeString(),
            'end_date' => $endDate->toDateTimeString(),
        ];
    }

    /**
     * Consolidated summary by cashier channel (Billing, F&B, Admin, Unattributed, Grand Total).
     *
     * @param Carbon|null $start
     * @param Carbon|null $end
     * @return array
     */
    public function getChannelConsolidatedSummary(?Carbon $start = null, ?Carbon $end = null): array
    {
        [$startDate, $endDate] = $this->resolveDateRange($start, $end);

        $invoices = $this->baseInvoiceQuery($startDate, $endDate)
            ->with(['cashierUser'])
            ->get();

        $billingCashiers = [];
        $fnbCashiers = [];
        $adminCashiers = [];
        $unattributedInvoicesTotal = 0.0;
        $unattributedInvoicesCount = 0;

        foreach ($invoices as $inv) {
            $user = $inv->cashierUser;
            $channel = $this->getCashierChannel($user);
            $userId = $user ? $user->id : 0;
            $userName = $user ? $user->name : 'Tidak Teratribusi';
            $amount = (float) $inv->final_total;
            $method = (string) $inv->payment_method;

            if ($channel === self::CHANNEL_BILLING) {
                if (!isset($billingCashiers[$userId])) {
                    $billingCashiers[$userId] = [
                        'id' => $userId,
                        'name' => $userName,
                        'channel' => self::CHANNEL_BILLING,
                        'total_net' => 0.0,
                        'invoice_count' => 0,
                        'payment_methods' => ['tunai' => 0.0, 'e-wallet' => 0.0, 'transfer_bank' => 0.0, 'other' => 0.0],
                    ];
                }
                $billingCashiers[$userId]['total_net'] += $amount;
                $billingCashiers[$userId]['invoice_count'] += 1;
                $mKey = in_array($method, ['tunai', 'e-wallet', 'transfer_bank'], true) ? $method : 'other';
                $billingCashiers[$userId]['payment_methods'][$mKey] += $amount;
            } elseif ($channel === self::CHANNEL_FNB) {
                if (!isset($fnbCashiers[$userId])) {
                    $fnbCashiers[$userId] = [
                        'id' => $userId,
                        'name' => $userName,
                        'channel' => self::CHANNEL_FNB,
                        'total_net' => 0.0,
                        'invoice_count' => 0,
                        'payment_methods' => ['tunai' => 0.0, 'e-wallet' => 0.0, 'transfer_bank' => 0.0, 'other' => 0.0],
                    ];
                }
                $fnbCashiers[$userId]['total_net'] += $amount;
                $fnbCashiers[$userId]['invoice_count'] += 1;
                $mKey = in_array($method, ['tunai', 'e-wallet', 'transfer_bank'], true) ? $method : 'other';
                $fnbCashiers[$userId]['payment_methods'][$mKey] += $amount;
            } elseif ($channel === self::CHANNEL_ADMIN) {
                if (!isset($adminCashiers[$userId])) {
                    $adminCashiers[$userId] = [
                        'id' => $userId,
                        'name' => $userName,
                        'channel' => self::CHANNEL_ADMIN,
                        'total_net' => 0.0,
                        'invoice_count' => 0,
                        'payment_methods' => ['tunai' => 0.0, 'e-wallet' => 0.0, 'transfer_bank' => 0.0, 'other' => 0.0],
                    ];
                }
                $adminCashiers[$userId]['total_net'] += $amount;
                $adminCashiers[$userId]['invoice_count'] += 1;
                $mKey = in_array($method, ['tunai', 'e-wallet', 'transfer_bank'], true) ? $method : 'other';
                $adminCashiers[$userId]['payment_methods'][$mKey] += $amount;
            } else {
                $unattributedInvoicesTotal += $amount;
                $unattributedInvoicesCount += 1;
            }
        }

        $totalBilling = array_sum(array_column($billingCashiers, 'total_net'));
        $totalFnb = array_sum(array_column($fnbCashiers, 'total_net'));
        $totalAdmin = array_sum(array_column($adminCashiers, 'total_net'));
        $totalAttributed = $totalBilling + $totalFnb + $totalAdmin + $unattributedInvoicesTotal;

        // Legacy transactions without invoices (historical unmigrated data)
        $legacyTxQuery = Transaction::query()
            ->where('payment_status', 'paid')
            ->whereRaw('COALESCE(paid_at, created_at) BETWEEN ? AND ?', [$startDate, $endDate])
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('invoices')
                    ->whereColumn('invoices.transaction_id', 'transactions.id_transaksi');
            });

        $legacyCount = (int) $legacyTxQuery->count();
        $legacyTotal = (float) $legacyTxQuery->select(DB::raw('COALESCE(SUM(total - COALESCE(diskon, 0)), 0) as net_total'))->value('net_total');

        $grandTotal = $totalAttributed + $legacyTotal;

        return [
            'billing_cashiers' => array_values($billingCashiers),
            'fnb_cashiers' => array_values($fnbCashiers),
            'admin_cashiers' => array_values($adminCashiers),
            'totals' => [
                'total_billing' => $totalBilling,
                'total_fnb' => $totalFnb,
                'total_admin' => $totalAdmin,
                'total_unattributed_invoices' => $unattributedInvoicesTotal,
                'total_attributed' => $totalAttributed,
                'total_legacy_unattributed' => $legacyTotal,
                'legacy_unattributed_count' => $legacyCount,
                'grand_total' => $grandTotal,
            ],
            'reconciliation_formula' => 'total_billing + total_fnb + total_admin + legacy_unattributed == grand_total',
            'is_reconciled' => abs(($totalBilling + $totalFnb + $totalAdmin + $unattributedInvoicesTotal + $legacyTotal) - $grandTotal) < 0.001,
            'start_date' => $startDate->toDateTimeString(),
            'end_date' => $endDate->toDateTimeString(),
        ];
    }

    /**
     * Revenue by Sales Category (PlayStation, Billiard, Cafe) with proportional discount allocation.
     * Guaranteed: sum(category_net) == total_invoices_net.
     *
     * @param Carbon|null $start
     * @param Carbon|null $end
     * @param int|null $cashierUserId
     * @return array
     */
    public function getCategoryRevenueSummary(?Carbon $start = null, ?Carbon $end = null, ?int $cashierUserId = null): array
    {
        [$startDate, $endDate] = $this->resolveDateRange($start, $end);

        $invoices = $this->baseInvoiceQuery($startDate, $endDate, $cashierUserId)
            ->with([
                'items',
                'transaction.playstation',
                'transaction.device.playstation',
            ])
            ->get();

        $categoryData = [
            self::CATEGORY_PLAYSTATION => ['label' => 'PlayStation', 'gross' => 0.0, 'discount' => 0.0, 'net' => 0.0, 'qty' => 0],
            self::CATEGORY_BILLIARD => ['label' => 'Billiard', 'gross' => 0.0, 'discount' => 0.0, 'net' => 0.0, 'qty' => 0],
            self::CATEGORY_CAFE => ['label' => 'Cafe / F&B', 'gross' => 0.0, 'discount' => 0.0, 'net' => 0.0, 'qty' => 0],
        ];

        $totalInvoicesNet = 0.0;
        $totalInvoicesGross = 0.0;
        $totalInvoicesDiscount = 0.0;

        foreach ($invoices as $invoice) {
            $invSubtotal = (float) $invoice->subtotal;
            $invDiscount = (float) $invoice->discount;
            $invFinal = (float) $invoice->final_total;

            $totalInvoicesNet += $invFinal;
            $totalInvoicesGross += $invSubtotal;
            $totalInvoicesDiscount += $invDiscount;

            $items = $invoice->items;
            if ($items->isEmpty()) {
                // If an invoice has no items, attribute to Rental or Cafe based on invoice type
                $targetCat = ($invoice->invoice_type === Invoice::TYPE_FNB_STANDALONE)
                    ? self::CATEGORY_CAFE
                    : self::CATEGORY_PLAYSTATION;
                $categoryData[$targetCat]['gross'] += $invSubtotal;
                $categoryData[$targetCat]['discount'] += $invDiscount;
                $categoryData[$targetCat]['net'] += $invFinal;
                $categoryData[$targetCat]['qty'] += 1;
                continue;
            }

            // Proportional discount allocation per item
            $allocatedItemDiscounts = [];
            $runningDiscountSum = 0.0;
            $maxSubtotalIndex = 0;
            $maxSubtotalVal = -1.0;

            foreach ($items as $idx => $item) {
                $itemSubtotal = (float) $item->subtotal;
                if ($itemSubtotal > $maxSubtotalVal) {
                    $maxSubtotalVal = $itemSubtotal;
                    $maxSubtotalIndex = $idx;
                }

                if ($invSubtotal > 0 && $invDiscount > 0) {
                    $ratio = $itemSubtotal / $invSubtotal;
                    $itemDiscount = round($invDiscount * $ratio, 2);
                } else {
                    $itemDiscount = 0.0;
                }

                $allocatedItemDiscounts[$idx] = $itemDiscount;
                $runningDiscountSum += $itemDiscount;
            }

            // Allocate rounding remainder to the item with the largest subtotal
            if ($invDiscount > 0 && abs($invDiscount - $runningDiscountSum) > 0.0001) {
                $remainder = round($invDiscount - $runningDiscountSum, 2);
                $allocatedItemDiscounts[$maxSubtotalIndex] += $remainder;
            }

            // Determine category for each item
            foreach ($items as $idx => $item) {
                $itemSubtotal = (float) $item->subtotal;
                $itemDiscount = $allocatedItemDiscounts[$idx] ?? 0.0;
                $itemNet = $itemSubtotal - $itemDiscount;
                $itemQty = (int) ($item->qty ?: 1);

                $cat = $this->resolveItemCategory($item, $invoice);

                $categoryData[$cat]['gross'] += $itemSubtotal;
                $categoryData[$cat]['discount'] += $itemDiscount;
                $categoryData[$cat]['net'] += $itemNet;
                $categoryData[$cat]['qty'] += $itemQty;
            }
        }

        $categoriesNetSum = $categoryData[self::CATEGORY_PLAYSTATION]['net']
            + $categoryData[self::CATEGORY_BILLIARD]['net']
            + $categoryData[self::CATEGORY_CAFE]['net'];

        return [
            'categories' => $categoryData,
            'total_gross' => $totalInvoicesGross,
            'total_discount' => $totalInvoicesDiscount,
            'total_net' => $totalInvoicesNet,
            'categories_net_sum' => $categoriesNetSum,
            'is_balanced' => abs($totalInvoicesNet - $categoriesNetSum) < 0.01,
            'start_date' => $startDate->toDateTimeString(),
            'end_date' => $endDate->toDateTimeString(),
        ];
    }

    /**
     * Resolve category (PLAYSTATION, BILLIARD, CAFE) for an InvoiceItem.
     */
    protected function resolveItemCategory(InvoiceItem $item, Invoice $invoice): string
    {
        if ($item->item_type === InvoiceItem::ITEM_TYPE_FNB_ITEM) {
            return self::CATEGORY_CAFE;
        }

        // 1. Snapshot name check (immutable historical record)
        $name = strtoupper(trim((string) $item->name));
        if (str_contains($name, 'BILLIARD') || str_contains($name, 'BILIAR') || str_contains($name, 'MEJA')) {
            return self::CATEGORY_BILLIARD;
        }
        if (str_contains($name, 'PLAYSTATION') || str_contains($name, 'PS3') || str_contains($name, 'PS4') || str_contains($name, 'PS5')) {
            return self::CATEGORY_PLAYSTATION;
        }

        // 2. Active device relationship check
        $tx = $invoice->transaction;
        $playstation = null;

        if ($tx) {
            $playstation = $tx->playstation
                ?? ($tx->device ? $tx->device->playstation : null);
        }

        if ($playstation instanceof Playstation) {
            $category = $playstation->getCategory();
            if ($category === Playstation::CATEGORY_BILLIARD) {
                return self::CATEGORY_BILLIARD;
            }
            if ($category === Playstation::CATEGORY_PLAYSTATION) {
                return self::CATEGORY_PLAYSTATION;
            }
        }

        // 3. Fallback name heuristics if device relation missing or changed
        if (str_starts_with($name, 'PS')) {
            return self::CATEGORY_PLAYSTATION;
        }

        return self::CATEGORY_PLAYSTATION;
    }

    /**
     * Hourly receipts distribution (00:00 to 23:00) for a specific date and optional cashier.
     *
     * @param Carbon $date
     * @param int|null $cashierUserId
     * @return array
     */
    public function getHourlyRevenueSeries(Carbon $date, ?int $cashierUserId = null): array
    {
        $tzDate = $date->copy()->setTimezone(self::TIMEZONE);
        $start = $tzDate->copy()->startOfDay();
        $end = $tzDate->copy()->endOfDay();

        $query = $this->baseInvoiceQuery($start, $end, $cashierUserId)
            ->selectRaw('HOUR(paid_at) as hour_num, SUM(final_total) as hourly_total, COUNT(*) as invoice_count')
            ->groupBy(DB::raw('HOUR(paid_at)'));

        $hourlyData = $query->pluck('hourly_total', 'hour_num')->all();
        $hourlyCounts = (clone $query)->pluck('invoice_count', 'hour_num')->all();

        $series = [];
        $grandTotal = 0.0;
        $totalInvoices = 0;

        for ($h = 0; $h < 24; $h++) {
            $val = isset($hourlyData[$h]) ? (float) $hourlyData[$h] : 0.0;
            $cnt = isset($hourlyCounts[$h]) ? (int) $hourlyCounts[$h] : 0;
            $grandTotal += $val;
            $totalInvoices += $cnt;

            $series[] = [
                'hour' => $h,
                'label' => sprintf('%02d:00', $h),
                'total' => $val,
                'count' => $cnt,
            ];
        }

        return [
            'date' => $tzDate->format('Y-m-d'),
            'cashier_user_id' => $cashierUserId,
            'total_revenue' => $grandTotal,
            'total_invoices' => $totalInvoices,
            'series' => $series,
        ];
    }

    /**
     * Unpaid operational receivables summary (running rentals and unpaid F&B orders).
     * Excluded from cash revenue.
     */
    public function getUnpaidReceivablesSummary(): array
    {
        $unpaidRentals = Transaction::query()
            ->where('payment_status', 'unpaid')
            ->whereIn('billing_status', [Transaction::BILLING_RUNNING, Transaction::BILLING_STOPPED_EARLY])
            ->get();

        $unpaidRentalCount = $unpaidRentals->count();
        $unpaidRentalTotal = (float) $unpaidRentals->sum(function ($tx) {
            return in_array($tx->tipe_transaksi, ['prepaid', 'custom_package'], true)
                ? (float) ($tx->paket_payable_total ?: $tx->total)
                : (float) $tx->total;
        });

        $unpaidFnbOrders = FnbOrder::query()
            ->with('items')
            ->where('payment_status', 'unpaid')
            ->whereNotIn('status', [FnbOrder::STATUS_CANCELLED, FnbOrder::STATUS_EXPIRED])
            ->get();

        $unpaidFnbCount = $unpaidFnbOrders->count();
        $unpaidFnbTotal = (float) $unpaidFnbOrders->sum('total_amount');

        return [
            'is_revenue' => false,
            'note' => 'Piutang operasional belum dibayar; BUKAN penerimaan kas lunas.',
            'unpaid_rentals' => [
                'count' => $unpaidRentalCount,
                'total' => $unpaidRentalTotal,
            ],
            'unpaid_fnb' => [
                'count' => $unpaidFnbCount,
                'total' => $unpaidFnbTotal,
            ],
            'total_receivables' => $unpaidRentalTotal + $unpaidFnbTotal,
        ];
    }

    /**
     * Invoices history query with standard filters and eager loads.
     */
    public function getInvoiceHistoryQuery(?Carbon $start = null, ?Carbon $end = null, ?int $cashierUserId = null, ?string $paymentMethod = null): Builder
    {
        [$startDate, $endDate] = $this->resolveDateRange($start, $end);

        $query = $this->baseInvoiceQuery($startDate, $endDate, $cashierUserId)
            ->with([
                'cashierUser',
                'transaction.device',
                'items.fnb',
            ])
            ->orderBy('paid_at', 'desc');

        if ($paymentMethod !== null && $paymentMethod !== '') {
            $query->where('payment_method', $paymentMethod);
        }

        return $query;
    }
}
