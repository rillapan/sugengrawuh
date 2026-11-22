<?php

namespace Tests\Feature\Finance;

use App\Models\Device;
use App\Models\Fnb;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Playstation;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\Finance\CashierRevenueService;
use App\Services\Finance\ShiftPeriodResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftCashierRevenueReportTest extends TestCase
{
    use RefreshDatabase;

    protected ShiftPeriodResolver $resolver;
    protected CashierRevenueService $revenueService;

    protected WorkShift $nightShift;
    protected WorkShift $morningShift;

    protected User $billingCashier;
    protected User $fnbCashier;
    protected User $secondCashier;
    protected User $cashierWithoutShift;
    protected User $adminUser;

    protected Playstation $psConsole;
    protected Device $psDevice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new ShiftPeriodResolver();
        $this->revenueService = new CashierRevenueService();

        // 1. Work shifts: night shift (19:00 - 03:00) and morning shift (06:00 - 14:00)
        $this->nightShift = WorkShift::create([
            'nama_shift' => 'Malam',
            'jam_mulai' => '19:00:00',
            'jam_selesai' => '03:00:00',
            'keterangan' => 'Shift Malam Operasional',
        ]);

        $this->morningShift = WorkShift::create([
            'nama_shift' => 'Pagi',
            'jam_mulai' => '06:00:00',
            'jam_selesai' => '14:00:00',
            'keterangan' => 'Shift Pagi Reguler',
        ]);

        // 2. Users
        $this->billingCashier = User::create([
            'name' => 'Budi Billing',
            'email' => 'budi.shift@test.com',
            'password' => bcrypt('password123'),
            'role' => 'kasir',
            'is_fnb_staff' => false,
            'work_shift_id' => $this->nightShift->id,
        ]);

        $this->fnbCashier = User::create([
            'name' => 'Siti FnB',
            'email' => 'siti.shift@test.com',
            'password' => bcrypt('password123'),
            'role' => 'kasir',
            'is_fnb_staff' => true,
            'work_shift_id' => $this->nightShift->id,
        ]);

        $this->secondCashier = User::create([
            'name' => 'Agus Billing 2',
            'email' => 'agus.shift@test.com',
            'password' => bcrypt('password123'),
            'role' => 'kasir',
            'is_fnb_staff' => false,
            'work_shift_id' => $this->nightShift->id,
        ]);

        $this->cashierWithoutShift = User::create([
            'name' => 'Doni NoShift',
            'email' => 'doni.noshift@test.com',
            'password' => bcrypt('password123'),
            'role' => 'kasir',
            'is_fnb_staff' => false,
            'work_shift_id' => null,
        ]);

        $this->adminUser = User::create([
            'name' => 'Owner Sugeng',
            'email' => 'owner.shift@test.com',
            'password' => bcrypt('password123'),
            'role' => 'owner',
        ]);

        // 3. Catalog & Devices
        $this->psConsole = Playstation::create([
            'nama' => 'PS5 Rental Test',
            'harga' => 15000,
        ]);

        $this->psDevice = Device::create([
            'nama' => 'PS5-SHIFT-01',
            'status' => 'Tersedia',
            'playstation_id' => $this->psConsole->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * SH-01: Shift 19.00–03.00 -> Interval lintas dua tanggal benar
     */
    public function test_sh01_overnight_shift_interval_crosses_two_dates_correctly(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-21 21:30:00', ShiftPeriodResolver::TIMEZONE));

        $period = $this->resolver->resolve($this->nightShift);

        $this->assertEquals($this->nightShift->id, $period['shift_id']);
        $this->assertEquals('Malam', $period['shift_name']);
        $this->assertEquals('2026-11-21', $period['operational_date']);
        $this->assertEquals('2026-11-21 19:00:00', $period['starts_at']);
        $this->assertEquals('2026-11-22 03:00:00', $period['ends_at']);
        $this->assertTrue($period['is_active']);
        $this->assertTrue($period['is_overnight']);
        $this->assertEquals(ShiftPeriodResolver::TIMEZONE, $period['timezone']);
    }

    /**
     * SH-02: Pembayaran 18.59.59 -> Tidak masuk shift
     */
    public function test_sh02_payment_at_18_59_59_is_excluded_from_shift(): void
    {
        $paidTime = Carbon::parse('2026-11-21 18:59:59', ShiftPeriodResolver::TIMEZONE);
        Carbon::setTestNow(Carbon::parse('2026-11-21 21:00:00', ShiftPeriodResolver::TIMEZONE));

        Invoice::create([
            'invoice_number' => 'INV-SH02',
            'subtotal' => 50000,
            'discount' => 0,
            'final_total' => 50000,
            'amount_paid' => 50000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->billingCashier->id,
            'paid_at' => $paidTime,
        ]);

        $period = $this->resolver->resolveForUser($this->billingCashier);
        $summary = $this->revenueService->getCashierShiftRevenueSummary(
            $period['start_carbon'],
            $period['end_carbon'],
            $this->billingCashier->id
        );

        $this->assertEquals(0.0, $summary['total_net']);
        $this->assertEquals(0, $summary['invoice_count']);
    }

    /**
     * SH-03: Pembayaran 19.00.00 -> Masuk shift
     */
    public function test_sh03_payment_at_19_00_00_is_included_in_shift(): void
    {
        $paidTime = Carbon::parse('2026-11-21 19:00:00', ShiftPeriodResolver::TIMEZONE);
        Carbon::setTestNow(Carbon::parse('2026-11-21 21:00:00', ShiftPeriodResolver::TIMEZONE));

        Invoice::create([
            'invoice_number' => 'INV-SH03',
            'subtotal' => 75000,
            'discount' => 0,
            'final_total' => 75000,
            'amount_paid' => 75000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->billingCashier->id,
            'paid_at' => $paidTime,
        ]);

        $period = $this->resolver->resolveForUser($this->billingCashier);
        $summary = $this->revenueService->getCashierShiftRevenueSummary(
            $period['start_carbon'],
            $period['end_carbon'],
            $this->billingCashier->id
        );

        $this->assertEquals(75000.0, $summary['total_net']);
        $this->assertEquals(1, $summary['invoice_count']);
    }

    /**
     * SH-04: Pembayaran 00.30 -> Masuk shift sebelumnya (operational date shift malam)
     */
    public function test_sh04_payment_at_00_30_belongs_to_previous_operational_date_shift(): void
    {
        $paidTime = Carbon::parse('2026-11-22 00:30:00', ShiftPeriodResolver::TIMEZONE);
        Carbon::setTestNow($paidTime);

        Invoice::create([
            'invoice_number' => 'INV-SH04',
            'subtotal' => 30000,
            'discount' => 0,
            'final_total' => 30000,
            'amount_paid' => 30000,
            'change_amount' => 0,
            'payment_method' => 'e-wallet',
            'cashier_user_id' => $this->billingCashier->id,
            'paid_at' => $paidTime,
        ]);

        $period = $this->resolver->resolveForUser($this->billingCashier);
        $this->assertEquals('2026-11-21', $period['operational_date']);
        $this->assertEquals('2026-11-21 19:00:00', $period['starts_at']);
        $this->assertEquals('2026-11-22 03:00:00', $period['ends_at']);
        $this->assertTrue($period['is_active']);

        $summary = $this->revenueService->getCashierShiftRevenueSummary(
            $period['start_carbon'],
            $period['end_carbon'],
            $this->billingCashier->id
        );

        $this->assertEquals(30000.0, $summary['total_net']);
        $this->assertEquals(1, $summary['invoice_count']);
    }

    /**
     * SH-05: Pembayaran 02.59.59 -> Masuk shift
     */
    public function test_sh05_payment_at_02_59_59_is_included_in_shift(): void
    {
        $paidTime = Carbon::parse('2026-11-22 02:59:59', ShiftPeriodResolver::TIMEZONE);
        Carbon::setTestNow($paidTime);

        Invoice::create([
            'invoice_number' => 'INV-SH05',
            'subtotal' => 45000,
            'discount' => 0,
            'final_total' => 45000,
            'amount_paid' => 50000,
            'change_amount' => 5000,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->billingCashier->id,
            'paid_at' => $paidTime,
        ]);

        $period = $this->resolver->resolveForUser($this->billingCashier);
        $summary = $this->revenueService->getCashierShiftRevenueSummary(
            $period['start_carbon'],
            $period['end_carbon'],
            $this->billingCashier->id
        );

        $this->assertEquals(45000.0, $summary['total_net']);
        $this->assertEquals(1, $summary['invoice_count']);
    }

    /**
     * SH-06: Pembayaran 03.00.00 -> Tidak masuk shift yang berakhir
     */
    public function test_sh06_payment_at_03_00_00_is_excluded_from_ended_shift(): void
    {
        $paidTime = Carbon::parse('2026-11-22 03:00:00', ShiftPeriodResolver::TIMEZONE);
        Carbon::setTestNow($paidTime);

        Invoice::create([
            'invoice_number' => 'INV-SH06',
            'subtotal' => 60000,
            'discount' => 0,
            'final_total' => 60000,
            'amount_paid' => 60000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->billingCashier->id,
            'paid_at' => $paidTime,
        ]);

        // Shift interval for 21 Nov night is [19:00:00, 03:00:00)
        $summary = $this->revenueService->getCashierShiftRevenueSummary(
            Carbon::parse('2026-11-21 19:00:00', ShiftPeriodResolver::TIMEZONE),
            Carbon::parse('2026-11-22 03:00:00', ShiftPeriodResolver::TIMEZONE),
            $this->billingCashier->id
        );

        $this->assertEquals(0.0, $summary['total_net']);
        $this->assertEquals(0, $summary['invoice_count']);
    }

    /**
     * SH-07: Dua kasir berbeda -> Tidak saling tercampur
     */
    public function test_sh07_two_different_cashiers_revenue_do_not_mix(): void
    {
        $paidTime = Carbon::parse('2026-11-21 21:00:00', ShiftPeriodResolver::TIMEZONE);
        Carbon::setTestNow($paidTime);

        Invoice::create([
            'invoice_number' => 'INV-KASIR-1',
            'subtotal' => 100000,
            'discount' => 0,
            'final_total' => 100000,
            'amount_paid' => 100000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->billingCashier->id,
            'paid_at' => $paidTime,
        ]);

        Invoice::create([
            'invoice_number' => 'INV-KASIR-2',
            'subtotal' => 80000,
            'discount' => 0,
            'final_total' => 80000,
            'amount_paid' => 80000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->secondCashier->id,
            'paid_at' => $paidTime,
        ]);

        $period1 = $this->resolver->resolveForUser($this->billingCashier);
        $summary1 = $this->revenueService->getCashierShiftRevenueSummary(
            $period1['start_carbon'],
            $period1['end_carbon'],
            $this->billingCashier->id
        );

        $period2 = $this->resolver->resolveForUser($this->secondCashier);
        $summary2 = $this->revenueService->getCashierShiftRevenueSummary(
            $period2['start_carbon'],
            $period2['end_carbon'],
            $this->secondCashier->id
        );

        $this->assertEquals(100000.0, $summary1['total_net']);
        $this->assertEquals(1, $summary1['invoice_count']);

        $this->assertEquals(80000.0, $summary2['total_net']);
        $this->assertEquals(1, $summary2['invoice_count']);
    }

    /**
     * SH-08: Kasir Billing vs F&B -> Channel tetap terpisah
     */
    public function test_sh08_billing_and_fnb_cashier_channels_remain_isolated(): void
    {
        $paidTime = Carbon::parse('2026-11-21 20:00:00', ShiftPeriodResolver::TIMEZONE);
        Carbon::setTestNow($paidTime);

        Invoice::create([
            'invoice_number' => 'INV-BILLING',
            'subtotal' => 50000,
            'discount' => 0,
            'final_total' => 50000,
            'amount_paid' => 50000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->billingCashier->id,
            'paid_at' => $paidTime,
        ]);

        Invoice::create([
            'invoice_number' => 'INV-FNB',
            'subtotal' => 40000,
            'discount' => 0,
            'final_total' => 40000,
            'amount_paid' => 40000,
            'change_amount' => 0,
            'payment_method' => 'transfer_bank',
            'cashier_user_id' => $this->fnbCashier->id,
            'paid_at' => $paidTime,
        ]);

        $period = $this->resolver->resolveForUser($this->billingCashier);

        $billingSummary = $this->revenueService->getCashierShiftRevenueSummary(
            $period['start_carbon'],
            $period['end_carbon'],
            $this->billingCashier->id
        );

        $fnbSummary = $this->revenueService->getCashierShiftRevenueSummary(
            $period['start_carbon'],
            $period['end_carbon'],
            $this->fnbCashier->id
        );

        $this->assertEquals(50000.0, $billingSummary['total_net']);
        $this->assertEquals(CashierRevenueService::CHANNEL_BILLING, $billingSummary['channel']);

        $this->assertEquals(40000.0, $fnbSummary['total_net']);
        $this->assertEquals(CashierRevenueService::CHANNEL_FNB, $fnbSummary['channel']);
    }

    /**
     * SH-09: Transaksi dibuat kemarin, dibayar hari ini -> Mengikuti paid_at
     */
    public function test_sh09_transaction_created_yesterday_paid_today_follows_paid_at(): void
    {
        $createdYesterday = Carbon::parse('2026-11-20 22:00:00', ShiftPeriodResolver::TIMEZONE);
        $paidDuringShift = Carbon::parse('2026-11-21 20:00:00', ShiftPeriodResolver::TIMEZONE);
        Carbon::setTestNow($paidDuringShift);

        $tx = Transaction::create([
            'nama' => 'Pemain Lama',
            'nama_pelanggan' => 'Pemain Lama',
            'device_id' => $this->psDevice->id,
            'playstation_id' => $this->psConsole->id,
            'user_id' => $this->billingCashier->id,
            'tipe_transaksi' => 'postpaid',
            'status' => 'Selesai',
            'status_transaksi' => 'selesai',
            'billing_status' => Transaction::BILLING_STOPPED_EARLY,
            'payment_status' => 'paid',
            'waktu_mulai' => '22:00',
            'waktu_selesai' => '00:00',
            'harga' => 15000,
            'total' => 30000,
            'created_at' => $createdYesterday,
            'paid_at' => $paidDuringShift,
        ]);

        Invoice::create([
            'invoice_number' => 'INV-SH09',
            'transaction_id' => $tx->id_transaksi,
            'subtotal' => 30000,
            'discount' => 0,
            'final_total' => 30000,
            'amount_paid' => 30000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->billingCashier->id,
            'created_at' => $createdYesterday,
            'paid_at' => $paidDuringShift,
        ]);

        $period = $this->resolver->resolveForUser($this->billingCashier);
        $summary = $this->revenueService->getCashierShiftRevenueSummary(
            $period['start_carbon'],
            $period['end_carbon'],
            $this->billingCashier->id
        );

        $this->assertEquals(30000.0, $summary['total_net']);
        $this->assertEquals(1, $summary['invoice_count']);
    }

    /**
     * SH-10: Invoice / Transaksi UNPAID -> Tidak masuk penerimaan
     */
    public function test_sh10_unpaid_invoices_are_excluded_from_revenue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-21 21:00:00', ShiftPeriodResolver::TIMEZONE));

        // Unpaid running rental transaction created during shift
        Transaction::create([
            'nama' => 'Pemain Unpaid',
            'nama_pelanggan' => 'Pemain Unpaid',
            'device_id' => $this->psDevice->id,
            'playstation_id' => $this->psConsole->id,
            'user_id' => $this->billingCashier->id,
            'tipe_transaksi' => 'prepaid',
            'status' => 'Berjalan',
            'status_transaksi' => 'draft',
            'billing_status' => Transaction::BILLING_RUNNING,
            'payment_status' => 'unpaid',
            'waktu_mulai' => '20:00',
            'waktu_selesai' => '22:00',
            'harga' => 30000,
            'total' => 30000,
            'paket_payable_total' => 30000,
            'paid_at' => null,
        ]);

        $period = $this->resolver->resolveForUser($this->billingCashier);
        $summary = $this->revenueService->getCashierShiftRevenueSummary(
            $period['start_carbon'],
            $period['end_carbon'],
            $this->billingCashier->id
        );

        $this->assertEquals(0.0, $summary['total_net']);
        $this->assertEquals(0, $summary['invoice_count']);
    }

    /**
     * SH-11: Shift melewati akhir bulan -> Benar
     */
    public function test_sh11_shift_crossing_month_boundary_resolves_and_aggregates_correctly(): void
    {
        // 31 October to 01 November 2026
        Carbon::setTestNow(Carbon::parse('2026-10-31 22:00:00', ShiftPeriodResolver::TIMEZONE));

        $period = $this->resolver->resolve($this->nightShift);
        $this->assertEquals('2026-10-31', $period['operational_date']);
        $this->assertEquals('2026-10-31 19:00:00', $period['starts_at']);
        $this->assertEquals('2026-11-01 03:00:00', $period['ends_at']);

        // Payment in October
        Invoice::create([
            'invoice_number' => 'INV-OCT',
            'subtotal' => 50000,
            'discount' => 0,
            'final_total' => 50000,
            'amount_paid' => 50000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->billingCashier->id,
            'paid_at' => Carbon::parse('2026-10-31 23:15:00', ShiftPeriodResolver::TIMEZONE),
        ]);

        // Payment in November
        Invoice::create([
            'invoice_number' => 'INV-NOV',
            'subtotal' => 40000,
            'discount' => 0,
            'final_total' => 40000,
            'amount_paid' => 40000,
            'change_amount' => 0,
            'payment_method' => 'e-wallet',
            'cashier_user_id' => $this->billingCashier->id,
            'paid_at' => Carbon::parse('2026-11-01 01:45:00', ShiftPeriodResolver::TIMEZONE),
        ]);

        $summary = $this->revenueService->getCashierShiftRevenueSummary(
            $period['start_carbon'],
            $period['end_carbon'],
            $this->billingCashier->id
        );

        $this->assertEquals(90000.0, $summary['total_net']);
        $this->assertEquals(2, $summary['invoice_count']);
    }

    /**
     * SH-12: Shift melewati akhir tahun -> Benar
     */
    public function test_sh12_shift_crossing_year_boundary_resolves_and_aggregates_correctly(): void
    {
        // 31 December 2026 to 01 January 2027
        Carbon::setTestNow(Carbon::parse('2027-01-01 01:30:00', ShiftPeriodResolver::TIMEZONE));

        $period = $this->resolver->resolve($this->nightShift);
        $this->assertEquals('2026-12-31', $period['operational_date']);
        $this->assertEquals('2026-12-31 19:00:00', $period['starts_at']);
        $this->assertEquals('2027-01-01 03:00:00', $period['ends_at']);

        Invoice::create([
            'invoice_number' => 'INV-2026',
            'subtotal' => 70000,
            'discount' => 0,
            'final_total' => 70000,
            'amount_paid' => 70000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->billingCashier->id,
            'paid_at' => Carbon::parse('2026-12-31 22:30:00', ShiftPeriodResolver::TIMEZONE),
        ]);

        Invoice::create([
            'invoice_number' => 'INV-2027',
            'subtotal' => 80000,
            'discount' => 0,
            'final_total' => 80000,
            'amount_paid' => 80000,
            'change_amount' => 0,
            'payment_method' => 'transfer_bank',
            'cashier_user_id' => $this->billingCashier->id,
            'paid_at' => Carbon::parse('2027-01-01 02:00:00', ShiftPeriodResolver::TIMEZONE),
        ]);

        $summary = $this->revenueService->getCashierShiftRevenueSummary(
            $period['start_carbon'],
            $period['end_carbon'],
            $this->billingCashier->id
        );

        $this->assertEquals(150000.0, $summary['total_net']);
        $this->assertEquals(2, $summary['invoice_count']);
    }

    /**
     * SH-13: Grafik per jam -> Total sama dengan ringkasan shift
     */
    public function test_sh13_hourly_revenue_series_sum_equals_total_cashier_shift_revenue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-21 23:00:00', ShiftPeriodResolver::TIMEZONE));
        $period = $this->resolver->resolveForUser($this->billingCashier);

        // Invoices across 5 different hours
        $invoicesData = [
            ['2026-11-21 19:30:00', 75000],
            ['2026-11-21 20:45:00', 50000],
            ['2026-11-21 23:15:00', 30000],
            ['2026-11-22 01:10:00', 25000],
            ['2026-11-22 02:20:00', 20000],
        ];

        foreach ($invoicesData as $idx => [$time, $amount]) {
            Invoice::create([
                'invoice_number' => 'INV-SERIES-' . $idx,
                'subtotal' => $amount,
                'discount' => 0,
                'final_total' => $amount,
                'amount_paid' => $amount,
                'change_amount' => 0,
                'payment_method' => 'tunai',
                'cashier_user_id' => $this->billingCashier->id,
                'paid_at' => Carbon::parse($time, ShiftPeriodResolver::TIMEZONE),
            ]);
        }

        $summary = $this->revenueService->getCashierShiftRevenueSummary(
            $period['start_carbon'],
            $period['end_carbon'],
            $this->billingCashier->id
        );

        $hourlySeries = $this->revenueService->getShiftHourlyRevenueSeries(
            $period['start_carbon'],
            $period['end_carbon'],
            $this->billingCashier->id
        );

        $seriesSum = array_sum(array_column($hourlySeries['series'], 'total'));
        $seriesCount = array_sum(array_column($hourlySeries['series'], 'count'));

        $this->assertEquals(200000.0, $summary['total_net']);
        $this->assertEquals(200000.0, $seriesSum);
        $this->assertEquals(5, $summary['invoice_count']);
        $this->assertEquals(5, $seriesCount);

        // Verify hours labels sequence: 19:00, 20:00, 21:00, 22:00, 23:00, 00:00, 01:00, 02:00
        $expectedLabels = ['19:00', '20:00', '21:00', '22:00', '23:00', '00:00', '01:00', '02:00'];
        $actualLabels = array_column($hourlySeries['series'], 'label');
        $this->assertEquals($expectedLabels, $actualLabels);
    }

    /**
     * SH-14: Shift sudah berakhir -> Menampilkan Shift Terakhir
     */
    public function test_sh14_shift_ended_displays_last_shift_as_historical_with_proper_label(): void
    {
        // 10:00:00 next day - night shift ended at 03:00:00
        Carbon::setTestNow(Carbon::parse('2026-11-22 10:00:00', ShiftPeriodResolver::TIMEZONE));

        $period = $this->resolver->resolveForUser($this->billingCashier);

        $this->assertFalse($period['is_active']);
        $this->assertEquals('Shift Terakhir', $period['status_label']);
        $this->assertEquals('2026-11-21', $period['operational_date']);
        $this->assertEquals('2026-11-21 19:00:00', $period['starts_at']);
        $this->assertEquals('2026-11-22 03:00:00', $period['ends_at']);

        // HTTP assertion on dashboard
        $response = $this->actingAs($this->billingCashier)->get(route('home'));
        $response->assertStatus(200);
        $response->assertSee('Penerimaan Kasir Billing — Shift Terakhir');
        $response->assertSee('Shift Terakhir');
    }

    /**
     * SH-15: Kasir tanpa work_shift_id -> Fallback aman dan jelas
     */
    public function test_sh15_cashier_without_work_shift_id_has_safe_clear_fallback(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-21 15:00:00', ShiftPeriodResolver::TIMEZONE));

        $period = $this->resolver->resolveForUser($this->cashierWithoutShift);

        $this->assertFalse($period['has_shift']);
        $this->assertFalse($period['is_active']);
        $this->assertEquals('Tanpa Shift', $period['shift_name']);
        $this->assertEquals('Tanpa Shift', $period['status_label']);
        $this->assertEquals('2026-11-21', $period['operational_date']);

        // Dashboard renders fallback gracefully
        $response = $this->actingAs($this->cashierWithoutShift)->get(route('home'));
        $response->assertStatus(200);
        $response->assertSee('Tanpa Jadwal Shift');
    }

    /**
     * SH-16: Owner/Admin mode Hari Ini -> Tetap berdasarkan tanggal kalender
     */
    public function test_sh16_admin_owner_today_mode_remains_calendar_based(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-21 14:00:00', ShiftPeriodResolver::TIMEZONE));

        // Invoice paid in morning at 10:00 (outside night shift, but inside today's calendar)
        Invoice::create([
            'invoice_number' => 'INV-CALENDAR',
            'subtotal' => 60000,
            'discount' => 0,
            'final_total' => 60000,
            'amount_paid' => 60000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->billingCashier->id,
            'paid_at' => Carbon::parse('2026-11-21 10:00:00', ShiftPeriodResolver::TIMEZONE),
        ]);

        $response = $this->actingAs($this->adminUser)->get(route('home', ['period' => 'today']));
        $response->assertStatus(200);
        $response->assertSee('Penerimaan Konsolidasi Usaha Hari Ini');
        $response->assertSee('Rp 60.000');
    }

    /**
     * SH-17: Owner/Admin mode Per Shift -> Menggunakan interval shift
     */
    public function test_sh17_admin_owner_per_shift_mode_uses_exact_shift_interval(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-21 21:00:00', ShiftPeriodResolver::TIMEZONE));

        // 1. Morning payment: outside night shift
        Invoice::create([
            'invoice_number' => 'INV-MORNING',
            'subtotal' => 60000,
            'discount' => 0,
            'final_total' => 60000,
            'amount_paid' => 60000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->billingCashier->id,
            'paid_at' => Carbon::parse('2026-11-21 10:00:00', ShiftPeriodResolver::TIMEZONE),
        ]);

        // 2. Night shift payment: inside night shift
        Invoice::create([
            'invoice_number' => 'INV-NIGHT',
            'subtotal' => 85000,
            'discount' => 0,
            'final_total' => 85000,
            'amount_paid' => 85000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->billingCashier->id,
            'paid_at' => Carbon::parse('2026-11-21 20:00:00', ShiftPeriodResolver::TIMEZONE),
        ]);

        // When admin visits with period=shift and shift_id=nightShift->id
        $response = $this->actingAs($this->adminUser)->get(route('home', [
            'period' => 'shift',
            'shift_id' => $this->nightShift->id,
            'date' => '2026-11-21',
        ]));

        $response->assertStatus(200);
        // Only 85,000 from the night shift interval is counted
        $response->assertSee('Rp 85.000');
        $response->assertDontSee('Rp 145.000');
    }

    /**
     * SH-18: Payment method breakdown -> Jumlah komponen sesuai total
     */
    public function test_sh18_payment_method_breakdown_components_sum_to_total_net(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-21 22:00:00', ShiftPeriodResolver::TIMEZONE));

        Invoice::create([
            'invoice_number' => 'INV-CASH',
            'subtotal' => 50000,
            'discount' => 0,
            'final_total' => 50000,
            'amount_paid' => 50000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->billingCashier->id,
            'paid_at' => Carbon::parse('2026-11-21 20:00:00', ShiftPeriodResolver::TIMEZONE),
        ]);

        Invoice::create([
            'invoice_number' => 'INV-EWALLET',
            'subtotal' => 30000,
            'discount' => 0,
            'final_total' => 30000,
            'amount_paid' => 30000,
            'change_amount' => 0,
            'payment_method' => 'e-wallet',
            'cashier_user_id' => $this->billingCashier->id,
            'paid_at' => Carbon::parse('2026-11-21 21:00:00', ShiftPeriodResolver::TIMEZONE),
        ]);

        Invoice::create([
            'invoice_number' => 'INV-TF',
            'subtotal' => 20000,
            'discount' => 0,
            'final_total' => 20000,
            'amount_paid' => 20000,
            'change_amount' => 0,
            'payment_method' => 'transfer_bank',
            'cashier_user_id' => $this->billingCashier->id,
            'paid_at' => Carbon::parse('2026-11-21 21:30:00', ShiftPeriodResolver::TIMEZONE),
        ]);

        $period = $this->resolver->resolveForUser($this->billingCashier);
        $summary = $this->revenueService->getCashierShiftRevenueSummary(
            $period['start_carbon'],
            $period['end_carbon'],
            $this->billingCashier->id
        );

        $methods = $summary['payment_methods'];
        $sumMethods = $methods['tunai'] + $methods['e-wallet'] + $methods['transfer_bank'] + $methods['other'];

        $this->assertEquals(100000.0, $summary['total_net']);
        $this->assertEquals(100000.0, $sumMethods);
        $this->assertEquals(50000.0, $methods['tunai']);
        $this->assertEquals(30000.0, $methods['e-wallet']);
        $this->assertEquals(20000.0, $methods['transfer_bank']);

        $counts = $summary['payment_method_counts'];
        $this->assertEquals(1, $counts['tunai']);
        $this->assertEquals(1, $counts['e-wallet']);
        $this->assertEquals(1, $counts['transfer_bank']);
        $this->assertEquals(3, $summary['invoice_count']);
    }

    /**
     * SH-19: Invoice Billing + FnB -> Tidak dihitung ganda
     */
    public function test_sh19_invoice_billing_and_fnb_anti_double_counting_in_shift_mode(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-21 22:00:00', ShiftPeriodResolver::TIMEZONE));
        $paidTime = Carbon::parse('2026-11-21 20:30:00', ShiftPeriodResolver::TIMEZONE);

        $snack = Fnb::create([
            'nama' => 'Roti Bakar',
            'harga_jual' => 20000,
            'harga_beli' => 10000,
            'stok' => 20,
            'status' => 'Tersedia',
        ]);

        $tx = Transaction::create([
            'nama' => 'Pak Ahmad',
            'nama_pelanggan' => 'Pak Ahmad',
            'device_id' => $this->psDevice->id,
            'playstation_id' => $this->psConsole->id,
            'user_id' => $this->billingCashier->id,
            'tipe_transaksi' => 'postpaid',
            'status' => 'Selesai',
            'status_transaksi' => 'selesai',
            'billing_status' => Transaction::BILLING_STOPPED_EARLY,
            'payment_status' => 'paid',
            'waktu_mulai' => '19:30',
            'waktu_selesai' => '20:30',
            'harga' => 15000,
            'total' => 35000, // 15.000 rental + 20.000 fnb
            'paid_at' => $paidTime,
        ]);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-COMBINED-SHIFT',
            'transaction_id' => $tx->id_transaksi,
            'invoice_type' => Invoice::TYPE_GAME_AND_FNB,
            'subtotal' => 35000,
            'discount' => 0,
            'final_total' => 35000,
            'amount_paid' => 35000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->billingCashier->id,
            'paid_at' => $paidTime,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_type' => InvoiceItem::ITEM_TYPE_GAME_RENTAL,
            'name' => 'Rental PS5 1 Jam',
            'qty' => 1,
            'unit_price_jual' => 15000,
            'subtotal' => 15000,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_type' => InvoiceItem::ITEM_TYPE_FNB_ITEM,
            'name' => 'Roti Bakar',
            'qty' => 1,
            'unit_price_jual' => 20000,
            'subtotal' => 20000,
        ]);

        $period = $this->resolver->resolveForUser($this->billingCashier);

        // Billing cashier receives full 35,000
        $billingSummary = $this->revenueService->getCashierShiftRevenueSummary(
            $period['start_carbon'],
            $period['end_carbon'],
            $this->billingCashier->id
        );
        $this->assertEquals(35000.0, $billingSummary['total_net']);

        // FnB cashier receives 0 from this settlement
        $fnbSummary = $this->revenueService->getCashierShiftRevenueSummary(
            $period['start_carbon'],
            $period['end_carbon'],
            $this->fnbCashier->id
        );
        $this->assertEquals(0.0, $fnbSummary['total_net']);

        // Consolidated summary
        $consolidated = $this->revenueService->getChannelConsolidatedSummary(
            $period['start_carbon'],
            $period['end_carbon'],
            isExact: true,
            exclusiveEnd: true
        );
        $this->assertEquals(35000.0, $consolidated['totals']['total_billing']);
        $this->assertEquals(0.0, $consolidated['totals']['total_fnb']);
        $this->assertEquals(35000.0, $consolidated['totals']['grand_total']);
        $this->assertTrue($consolidated['is_reconciled']);
    }

    /**
     * SH-20: Refresh dashboard saat 00.00 -> Periode shift tidak berubah
     */
    public function test_sh20_refresh_dashboard_at_midnight_maintains_shift_period(): void
    {
        // 1. Before midnight: 23:59:59
        Carbon::setTestNow(Carbon::parse('2026-11-21 23:59:59', ShiftPeriodResolver::TIMEZONE));

        Invoice::create([
            'invoice_number' => 'INV-PRE-MIDNIGHT',
            'subtotal' => 50000,
            'discount' => 0,
            'final_total' => 50000,
            'amount_paid' => 50000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->billingCashier->id,
            'paid_at' => Carbon::now(ShiftPeriodResolver::TIMEZONE),
        ]);

        $periodPre = $this->resolver->resolveForUser($this->billingCashier);
        $this->assertEquals('2026-11-21', $periodPre['operational_date']);
        $this->assertEquals('2026-11-21 19:00:00', $periodPre['starts_at']);
        $this->assertEquals('2026-11-22 03:00:00', $periodPre['ends_at']);

        // 2. Exactly at midnight: 00:00:00
        Carbon::setTestNow(Carbon::parse('2026-11-22 00:00:00', ShiftPeriodResolver::TIMEZONE));

        $periodMid = $this->resolver->resolveForUser($this->billingCashier);
        $this->assertEquals('2026-11-21', $periodMid['operational_date']);
        $this->assertEquals('2026-11-21 19:00:00', $periodMid['starts_at']);
        $this->assertEquals('2026-11-22 03:00:00', $periodMid['ends_at']);
        $this->assertTrue($periodMid['is_active']);

        // Revenue from before midnight is retained at 00:00:00
        $summary = $this->revenueService->getCashierShiftRevenueSummary(
            $periodMid['start_carbon'],
            $periodMid['end_carbon'],
            $this->billingCashier->id
        );
        $this->assertEquals(50000.0, $summary['total_net']);
        $this->assertEquals(1, $summary['invoice_count']);

        // Dashboard renders seamlessly across 00:00:00
        $response = $this->actingAs($this->billingCashier)->get(route('home'));
        $response->assertStatus(200);
        $response->assertSee('Rp 50.000');
        $response->assertSee('Penerimaan Kasir Billing — Shift Aktif');
    }

    /**
     * Additional Endpoint Test: GET /hourly-revenue-data returns shift series for cashier
     */
    public function test_hourly_revenue_endpoint_returns_shift_buckets_for_cashier(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-21 21:00:00', ShiftPeriodResolver::TIMEZONE));

        Invoice::create([
            'invoice_number' => 'INV-HOURLY-01',
            'subtotal' => 40000,
            'discount' => 0,
            'final_total' => 40000,
            'amount_paid' => 40000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->billingCashier->id,
            'paid_at' => Carbon::parse('2026-11-21 20:15:00', ShiftPeriodResolver::TIMEZONE),
        ]);

        $response = $this->actingAs($this->billingCashier)->getJson('/hourly-revenue-data');
        $response->assertStatus(200);

        $json = $response->json();
        $this->assertEquals(['19:00', '20:00', '21:00', '22:00', '23:00', '00:00', '01:00', '02:00'], $json['labels']);
        $this->assertEquals('Penerimaan Kasir Billing — Shift Aktif', $json['datasets'][0]['label']);
        $this->assertEquals(40000.0, array_sum($json['datasets'][0]['data']));
        $this->assertEquals(40000.0, $json['datasets'][0]['data'][1]); // 20:00 bucket
    }
}
