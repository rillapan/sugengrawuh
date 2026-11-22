<?php

namespace Tests\Feature\Billing;

use App\Models\AutomationIntent;
use App\Models\CustomPackage;
use App\Models\Device;
use App\Models\Fnb;
use App\Models\FnbOrder;
use App\Models\FnbOrderItem;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\Transaction;
use App\Models\TransactionFnb;
use App\Models\User;
use App\Services\Billing\PaketBillingService;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostSessionBackendSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $kasir;
    protected Playstation $playstation;
    protected Device $device;
    protected Location $location;
    protected Fnb $fnbKopi;
    protected Fnb $fnbSnack;
    protected BillingService $billingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('kasir_ps_test', DB::connection()->getDatabaseName(), 'Must run on kasir_ps_test');

        Config::set('hardware.driver', 'mock');
        Config::set('hardware.real_execution_enabled', false);
        Config::set('hardware.simulation_enabled', true);
        Config::set('hardware.automation_enabled', true);
        Config::set('hardware.allowed_environments', ['local', 'testing']);
        Config::set('hardware.allowed_databases', ['kasir_ps_test']);

        Carbon::setTestNow(Carbon::parse('2026-11-22 10:00:00'));

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin.postsess@sugengrawuh.test',
        ]);

        $this->kasir = User::factory()->create([
            'role' => 'kasir',
            'email' => 'kasir.postsess@sugengrawuh.test',
        ]);

        $this->playstation = Playstation::create([
            'nama' => 'PS5 PostSession',
            'harga' => 10000,
        ]);

        $this->device = Device::create([
            'nama' => 'PS5 Station 1',
            'playstation_id' => $this->playstation->id,
            'status' => 'Tersedia',
            'session_generation' => 0,
        ]);
        $this->device->playstations()->attach($this->playstation->id);

        $this->location = Location::create([
            'name' => 'Bilik 1',
            'code' => 'STATION-PS-01',
            'type' => 'station',
            'device_id' => $this->device->id,
            'is_active' => true,
        ]);

        $physicalDevice = PhysicalDevice::create([
            'name' => 'Relay Station 1',
            'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
            'brand' => PhysicalDevice::BRAND_SHELLY,
            'model' => 'Shelly 1PM Mini Gen3',
            'mac_address' => 'AA:BB:CC:DD:EE:11',
            'ip_address' => '192.168.1.151',
            'is_active' => true,
            'connection_status' => 'ONLINE',
            'verified_capabilities' => ['SWITCH_ON', 'SWITCH_OFF', 'GET_STATUS'],
        ]);

        StationDeviceMapping::create([
            'station_id' => $this->device->id,
            'physical_device_id' => $physicalDevice->id,
            'mapping_role' => StationDeviceMapping::ROLE_LIGHT_RELAY,
            'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
            'attached_by_user_id' => $this->admin->id,
            'attached_at' => now(),
        ]);

        $this->fnbKopi = Fnb::create([
            'nama' => 'Kopi Hitam',
            'harga_beli' => 3000,
            'harga_jual' => 5000,
            'stok' => 30,
        ]);

        $this->fnbSnack = Fnb::create([
            'nama' => 'Kentang Goreng',
            'harga_beli' => 6000,
            'harga_jual' => 12000,
            'stok' => 30,
        ]);

        $this->billingService = app(BillingService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function test_resolve_outstanding_unpaid_transactions_covers_all_non_draft_unpaid_statuses_and_filters(): void
    {
        $device2 = Device::create([
            'nama' => 'PS5 Station 2',
            'playstation_id' => $this->playstation->id,
            'status' => 'Tersedia',
        ]);
        $device2->playstations()->attach($this->playstation->id);

        // 1. Cross-midnight EXPIRED_PENDING_ACTION unpaid
        $expiredTx = Transaction::create([
            'status' => 'user',
            'nama' => 'Expired Cross Midnight',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'harga' => 10000,
            'jam_main' => 2,
            'duration_seconds' => 7200,
            'waktu_mulai' => '23:00',
            'waktu_Selesai' => '01:00',
            'started_at' => Carbon::parse('2026-11-21 23:00:00'),
            'expires_at' => Carbon::parse('2026-11-22 01:00:00'),
            'ended_at' => Carbon::parse('2026-11-22 01:00:00'),
            'status_transaksi' => 'selesai',
            'billing_status' => Transaction::BILLING_EXPIRED_PENDING_ACTION,
            'payment_status' => 'unpaid',
            'total' => 20000,
        ]);

        // 2. STOPPED_EARLY unpaid on device 1
        $stoppedTx = Transaction::create([
            'status' => 'user',
            'nama' => 'Stopped Early Unpaid',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'harga' => 10000,
            'jam_main' => 1,
            'duration_seconds' => 3600,
            'waktu_mulai' => '08:00',
            'waktu_Selesai' => '08:30',
            'started_at' => Carbon::parse('2026-11-22 08:00:00'),
            'expires_at' => Carbon::parse('2026-11-22 09:00:00'),
            'ended_at' => Carbon::parse('2026-11-22 08:30:00'),
            'status_transaksi' => 'selesai',
            'billing_status' => Transaction::BILLING_STOPPED_EARLY,
            'payment_status' => 'unpaid',
            'total' => 10000,
        ]);

        // 3. Legacy selesai (billing_status = null) unpaid on device 1
        $legacyFinishedTx = Transaction::create([
            'status' => 'user',
            'nama' => 'Legacy Finished Unpaid',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'harga' => 10000,
            'jam_main' => 1,
            'waktu_mulai' => '09:00',
            'waktu_Selesai' => '10:00',
            'status_transaksi' => 'selesai',
            'billing_status' => null,
            'payment_status' => 'unpaid',
            'total' => 10000,
        ]);

        // 4. status_transaksi = null unpaid on device 2 (must NOT be dropped by SQL != 'draft')
        $nullStatusTx = Transaction::create([
            'status' => 'user',
            'nama' => 'Null Status Unpaid',
            'device_id' => $device2->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'harga' => 10000,
            'waktu_mulai' => '09:00',
            'status_transaksi' => null,
            'billing_status' => Transaction::BILLING_COMPLETED,
            'payment_status' => 'unpaid',
            'total' => 10000,
        ]);

        // 5. Excluded rows: draft, paid, and active running excluded ID
        Transaction::create([
            'status' => 'user',
            'nama' => 'Upfront Draft',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'harga' => 10000,
            'jam_main' => 1,
            'waktu_mulai' => '09:30',
            'waktu_Selesai' => '10:30',
            'status_transaksi' => 'draft',
            'billing_status' => Transaction::BILLING_IDLE,
            'payment_status' => 'unpaid',
            'total' => 10000,
        ]);

        Transaction::create([
            'status' => 'user',
            'nama' => 'Already Paid',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'harga' => 10000,
            'jam_main' => 1,
            'waktu_mulai' => '07:00',
            'waktu_Selesai' => '08:00',
            'status_transaksi' => 'selesai',
            'billing_status' => Transaction::BILLING_COMPLETED,
            'payment_status' => 'paid',
            'total' => 10000,
        ]);

        $runningTx = Transaction::create([
            'status' => 'user',
            'nama' => 'Currently Running',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'harga' => 10000,
            'waktu_mulai' => '09:50',
            'started_at' => Carbon::now()->subMinutes(10),
            'lost_time_start' => Carbon::now()->subMinutes(10),
            'status_transaksi' => 'berjalan',
            'billing_status' => Transaction::BILLING_RUNNING,
            'payment_status' => 'unpaid',
            'total' => 0,
        ]);

        // Empty normalized deviceIds must return empty collection
        $this->assertTrue(
            $this->billingService->resolveOutstandingUnpaidTransactionsByDevice(collect([0, -1, '']))->isEmpty()
        );

        $grouped = $this->billingService->resolveOutstandingUnpaidTransactionsByDevice(
            collect([$this->device->id, $device2->id]),
            ['playstation'],
            collect([$runningTx->id_transaksi])
        );

        $this->assertTrue($grouped->has($this->device->id));
        $this->assertTrue($grouped->has($device2->id));

        // Ordered latest id_transaksi DESC
        $this->assertSame(
            [$legacyFinishedTx->id_transaksi, $stoppedTx->id_transaksi, $expiredTx->id_transaksi],
            $grouped->get($this->device->id)->pluck('id_transaksi')->all()
        );
        $this->assertSame(
            [$nullStatusTx->id_transaksi],
            $grouped->get($device2->id)->pluck('id_transaksi')->all()
        );
    }

    /** @test */
    public function test_summarize_transaction_billing_handles_prepaid_custom_package_and_postpaid_consistently(): void
    {
        $customPackage = CustomPackage::create([
            'nama_paket' => 'Paket Hemat',
            'harga_total' => 18000,
            'is_active' => true,
        ]);
        $customPackage->playstations()->attach($this->playstation->id, ['lama_main' => 120]);
        $customPackage->fnbs()->attach($this->fnbKopi->id, ['quantity' => 1]);

        $customTx = Transaction::create([
            'status' => 'user',
            'nama' => 'Custom Pkg Customer',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'custom_package_id' => $customPackage->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'custom_package',
            'harga' => 18000,
            'jam_main' => 120,
            'duration_seconds' => 7200,
            'waktu_mulai' => '09:00',
            'waktu_Selesai' => '11:00',
            'started_at' => Carbon::parse('2026-11-22 09:00:00'),
            'expires_at' => Carbon::parse('2026-11-22 11:00:00'),
            'status_transaksi' => 'sukses',
            'billing_status' => Transaction::BILLING_RUNNING,
            'payment_status' => 'unpaid',
            'total' => 23000,
        ]);

        // 2 Kopi (1 included in package, 1 chargeable = 5000)
        TransactionFnb::create([
            'transaction_id' => $customTx->id_transaksi,
            'fnb_id' => $this->fnbKopi->id,
            'qty' => 2,
            'harga_jual' => 5000,
            'harga_beli' => 3000,
        ]);

        // Unpaid FnbOrder (12000), Paid FnbOrder (5000), Cancelled FnbOrder (99000 - ignored)
        $unpaidOrd = FnbOrder::create([
            'order_number' => 'ORD-UNPAID-1',
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $this->location->id,
            'transaction_id' => $customTx->id_transaksi,
            'customer_name' => $customTx->nama,
            'status' => FnbOrder::STATUS_ACCEPTED,
            'payment_status' => 'unpaid',
        ]);
        FnbOrderItem::create([
            'fnb_order_id' => $unpaidOrd->id,
            'fnb_id' => $this->fnbSnack->id,
            'qty' => 1,
            'unit_price_beli' => 6000,
            'unit_price_jual' => 12000,
            'subtotal' => 12000,
            'payment_status' => 'unpaid',
        ]);

        $paidOrd = FnbOrder::create([
            'order_number' => 'ORD-PAID-1',
            'source' => FnbOrder::SOURCE_CASHIER,
            'location_id' => $this->location->id,
            'transaction_id' => $customTx->id_transaksi,
            'customer_name' => $customTx->nama,
            'status' => FnbOrder::STATUS_ACCEPTED,
            'payment_status' => 'paid',
        ]);
        FnbOrderItem::create([
            'fnb_order_id' => $paidOrd->id,
            'fnb_id' => $this->fnbKopi->id,
            'qty' => 1,
            'unit_price_beli' => 3000,
            'unit_price_jual' => 5000,
            'subtotal' => 5000,
            'payment_status' => 'paid',
        ]);

        $cancelledOrd = FnbOrder::create([
            'order_number' => 'ORD-CANCELLED-1',
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $this->location->id,
            'transaction_id' => $customTx->id_transaksi,
            'customer_name' => $customTx->nama,
            'status' => FnbOrder::STATUS_CANCELLED,
            'payment_status' => 'unpaid',
        ]);
        FnbOrderItem::create([
            'fnb_order_id' => $cancelledOrd->id,
            'fnb_id' => $this->fnbSnack->id,
            'qty' => 5,
            'unit_price_beli' => 6000,
            'unit_price_jual' => 12000,
            'subtotal' => 60000,
            'payment_status' => 'unpaid',
        ]);

        $summary = $this->billingService->summarizeTransactionBilling($customTx->fresh());
        $this->assertSame(18000.0, $summary['rental_subtotal']);
        $this->assertSame(5000.0, $summary['fnb_paid_total']);
        $this->assertSame(17000.0, $summary['fnb_unpaid_total']); // 5000 extra legacy + 12000 unpaid order
        $this->assertSame(22000.0, $summary['total_fnb_display']);
        $this->assertSame(35000.0, $summary['payable_total']); // 18000 + 17000
        $this->assertCount(3, $summary['fnb_items']);

        // Finished postpaid (90 mins @ 10k/hr = 15k) + unpaid order 12k
        $postpaidTx = Transaction::create([
            'status' => 'user',
            'nama' => 'Postpaid Stopped',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'harga' => 10000,
            'waktu_mulai' => '08:00',
            'waktu_Selesai' => '09:30',
            'started_at' => Carbon::parse('2026-11-22 08:00:00'),
            'ended_at' => Carbon::parse('2026-11-22 09:30:00'),
            'status_transaksi' => 'selesai',
            'billing_status' => Transaction::BILLING_COMPLETED,
            'payment_status' => 'unpaid',
            'total' => 15000, // stale total before new FnbOrder
        ]);

        $postpaidOrd = FnbOrder::create([
            'order_number' => 'ORD-POSTPAID-1',
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $this->location->id,
            'transaction_id' => $postpaidTx->id_transaksi,
            'customer_name' => $postpaidTx->nama,
            'status' => FnbOrder::STATUS_ACCEPTED,
            'payment_status' => 'unpaid',
        ]);
        FnbOrderItem::create([
            'fnb_order_id' => $postpaidOrd->id,
            'fnb_id' => $this->fnbSnack->id,
            'qty' => 1,
            'unit_price_beli' => 6000,
            'unit_price_jual' => 12000,
            'subtotal' => 12000,
            'payment_status' => 'unpaid',
        ]);

        $postpaidSummary = $this->billingService->summarizeTransactionBilling($postpaidTx->fresh());
        $this->assertSame(15000.0, $postpaidSummary['rental_subtotal']);
        $this->assertSame(12000.0, $postpaidSummary['fnb_unpaid_total']);
        $this->assertSame(27000.0, $postpaidSummary['payable_total']);

        // Paid draft Paket with TransactionFnb + Location must NOT double count mirrored SOURCE_CASHIER FnbOrder
        $device2 = Device::create([
            'nama' => 'PS5 Station 2',
            'playstation_id' => $this->playstation->id,
            'status' => 'Tersedia',
        ]);
        $device2->playstations()->attach($this->playstation->id);
        Location::create([
            'name' => 'Bilik 2',
            'code' => 'STATION-PS-02',
            'type' => 'station',
            'device_id' => $device2->id,
            'is_active' => true,
        ]);

        $draftWithFnb = Transaction::create([
            'status' => 'user',
            'nama' => 'Draft With FnB',
            'device_id' => $device2->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'harga' => 10000,
            'jam_main' => 1,
            'duration_seconds' => 3600,
            'waktu_mulai' => '10:00',
            'waktu_Selesai' => '11:00',
            'status_transaksi' => 'draft',
            'billing_status' => Transaction::BILLING_IDLE,
            'payment_status' => 'unpaid',
            'total' => 20000,
        ]);
        TransactionFnb::create([
            'transaction_id' => $draftWithFnb->id_transaksi,
            'fnb_id' => $this->fnbKopi->id,
            'qty' => 2,
            'harga_jual' => 5000,
            'harga_beli' => 3000,
        ]);
        app(PaketBillingService::class)->activatePaidPaket($draftWithFnb, [
            'payment_method' => 'tunai',
            'amount_paid' => 20000,
            'cashier_user_id' => $this->kasir->id,
        ]);

        $paidSummary = $this->billingService->summarizeTransactionBilling($draftWithFnb->fresh());
        $this->assertSame(10000.0, $paidSummary['rental_subtotal']);
        $this->assertSame(10000.0, $paidSummary['fnb_paid_total']);
        $this->assertSame(0.0, $paidSummary['fnb_unpaid_total']);
        $this->assertSame(10000.0, $paidSummary['total_fnb_display']);
        $this->assertSame(0.0, $paidSummary['payable_total']);
        $this->assertCount(1, $paidSummary['fnb_items']);
    }

    /** @test */
    public function test_evaluate_expiration_keeps_device_occupied_if_another_running_session_exists(): void
    {
        $this->device->update(['status' => 'Digunakan']);

        $expiredTx = Transaction::create([
            'status' => 'user',
            'nama' => 'Expired Session',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'harga' => 10000,
            'jam_main' => 1,
            'duration_seconds' => 3600,
            'waktu_mulai' => '08:00',
            'waktu_Selesai' => '09:00',
            'started_at' => Carbon::parse('2026-11-22 08:00:00'),
            'expires_at' => Carbon::parse('2026-11-22 09:00:00'),
            'status_transaksi' => 'sukses',
            'billing_status' => Transaction::BILLING_RUNNING,
            'payment_status' => 'unpaid',
            'total' => 10000,
        ]);

        $stillRunningTx = Transaction::create([
            'status' => 'user',
            'nama' => 'Current Running Session',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'harga' => 10000,
            'waktu_mulai' => '09:30',
            'started_at' => Carbon::parse('2026-11-22 09:30:00'),
            'lost_time_start' => Carbon::parse('2026-11-22 09:30:00'),
            'status_transaksi' => 'berjalan',
            'billing_status' => Transaction::BILLING_RUNNING,
            'payment_status' => 'unpaid',
            'total' => 0,
        ]);

        $res = $this->billingService->evaluateExpiration(Carbon::now());
        $this->assertSame(1, $res['count']);

        $expiredTx->refresh();
        $this->assertSame(Transaction::BILLING_EXPIRED_PENDING_ACTION, $expiredTx->billing_status);
        $this->assertSame('selesai', $expiredTx->status_transaksi);
        $this->assertSame('unpaid', $expiredTx->payment_status);
        $this->assertSame('2026-11-22 09:00:00', $expiredTx->ended_at->toDateTimeString());

        // Station must stay Digunakan because $stillRunningTx is still RUNNING
        $this->assertSame('Digunakan', $this->device->fresh()->status);
        $this->assertSame(Transaction::BILLING_RUNNING, $stillRunningTx->fresh()->billing_status);
        $this->assertDatabaseHas('automation_intents', [
            'transaction_id' => $expiredTx->id_transaksi,
            'event_type' => AutomationIntent::EVENT_BILLING_EXPIRED,
            'desired_state' => AutomationIntent::STATE_OFF,
        ]);
    }

    /** @test */
    public function test_paket_billing_service_settles_legacy_finished_while_station_occupied_without_hardware_or_timestamp_mutation(): void
    {
        // Occupy station with a new running session
        $newRunning = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'nama' => 'New Active Player',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        // Create legacy finished bill (billing_status = null, status_transaksi = 'selesai')
        $legacyFinished = Transaction::create([
            'status' => 'user',
            'nama' => 'Legacy Finished Bill',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'harga' => 10000,
            'jam_main' => 2,
            'duration_seconds' => 7200,
            'waktu_mulai' => '06:00',
            'waktu_Selesai' => '08:00',
            'started_at' => Carbon::parse('2026-11-22 06:00:00'),
            'expires_at' => Carbon::parse('2026-11-22 08:00:00'),
            'ended_at' => Carbon::parse('2026-11-22 08:00:00'),
            'status_transaksi' => 'selesai',
            'billing_status' => null,
            'payment_status' => 'unpaid',
            'total' => 20000,
        ]);

        // Also create legacy finished with ended_at != null and status_transaksi = 'sukses'
        $legacyEndedAtOnly = Transaction::create([
            'status' => 'user',
            'nama' => 'Legacy EndedAt Bill',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'harga' => 10000,
            'jam_main' => 1,
            'duration_seconds' => 3600,
            'waktu_mulai' => '05:00',
            'waktu_Selesai' => '06:00',
            'started_at' => Carbon::parse('2026-11-22 05:00:00'),
            'expires_at' => Carbon::parse('2026-11-22 06:00:00'),
            'ended_at' => Carbon::parse('2026-11-22 06:00:00'),
            'status_transaksi' => 'sukses',
            'billing_status' => null,
            'payment_status' => 'unpaid',
            'total' => 10000,
        ]);

        $paketService = app(PaketBillingService::class);
        $inv1 = $paketService->activatePaidPaket($legacyFinished, [
            'payment_method' => 'tunai',
            'amount_paid' => 20000,
            'cashier_user_id' => $this->kasir->id,
        ]);

        $inv2 = $paketService->activatePaidPaket($legacyEndedAtOnly, [
            'payment_method' => 'tunai',
            'amount_paid' => 10000,
            'cashier_user_id' => $this->kasir->id,
        ]);

        $legacyFinished->refresh();
        $this->assertSame('paid', $legacyFinished->payment_status);
        $this->assertNull($legacyFinished->billing_status);
        $this->assertSame('2026-11-22 06:00:00', $legacyFinished->started_at->toDateTimeString());
        $this->assertSame('2026-11-22 08:00:00', $legacyFinished->expires_at->toDateTimeString());
        $this->assertSame('2026-11-22 08:00:00', $legacyFinished->ended_at->toDateTimeString());
        $this->assertSame(0, AutomationIntent::where('transaction_id', $legacyFinished->id_transaksi)->count());

        $legacyEndedAtOnly->refresh();
        $this->assertSame('paid', $legacyEndedAtOnly->payment_status);
        $this->assertNull($legacyEndedAtOnly->billing_status);
        $this->assertSame('2026-11-22 05:00:00', $legacyEndedAtOnly->started_at->toDateTimeString());
        $this->assertSame('2026-11-22 06:00:00', $legacyEndedAtOnly->ended_at->toDateTimeString());
        $this->assertSame(0, AutomationIntent::where('transaction_id', $legacyEndedAtOnly->id_transaksi)->count());

        // Also verify G2 finished states (EXPIRED_PENDING_ACTION, STOPPED_EARLY, COMPLETED) settle without affecting station or hardware
        $g2Expired = Transaction::create([
            'status' => 'user',
            'nama' => 'G2 Expired',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'harga' => 10000,
            'jam_main' => 1,
            'duration_seconds' => 3600,
            'waktu_mulai' => '04:00',
            'waktu_Selesai' => '05:00',
            'started_at' => Carbon::parse('2026-11-22 04:00:00'),
            'expires_at' => Carbon::parse('2026-11-22 05:00:00'),
            'ended_at' => Carbon::parse('2026-11-22 05:00:00'),
            'status_transaksi' => 'selesai',
            'billing_status' => Transaction::BILLING_EXPIRED_PENDING_ACTION,
            'payment_status' => 'unpaid',
            'total' => 10000,
        ]);
        $invExp = $paketService->activatePaidPaket($g2Expired, [
            'payment_method' => 'tunai',
            'amount_paid' => 10000,
            'cashier_user_id' => $this->kasir->id,
        ]);
        $g2Expired->refresh();
        $this->assertSame('paid', $g2Expired->payment_status);
        $this->assertSame(Transaction::BILLING_EXPIRED_PENDING_ACTION, $g2Expired->billing_status);
        $this->assertSame('2026-11-22 05:00:00', $g2Expired->ended_at->toDateTimeString());
        $this->assertSame(0, AutomationIntent::where('transaction_id', $g2Expired->id_transaksi)->count());
        $this->assertSame(10000.0, (float) $invExp->final_total);

        // Station and new running session completely unaffected
        $this->assertSame('Digunakan', $this->device->fresh()->status);
        $this->assertSame(Transaction::BILLING_RUNNING, $newRunning->fresh()->billing_status);

        // Idempotent guard on repeated call
        $inv1Again = $paketService->activatePaidPaket($legacyFinished, [
            'payment_method' => 'tunai',
            'amount_paid' => 20000,
            'cashier_user_id' => $this->kasir->id,
        ]);
        $this->assertSame($inv1->id, $inv1Again->id);
        $this->assertSame(1, Invoice::where('transaction_id', $legacyFinished->id_transaksi)->count());
        $this->assertSame(1, Invoice::where('transaction_id', $legacyEndedAtOnly->id_transaksi)->count());
    }

    /** @test */
    public function test_paket_billing_service_settles_legacy_sukses_null_billing_status_and_null_ended_at_safely(): void
    {
        $paketService = app(PaketBillingService::class);

        // 1. Expired legacy/pre-G2 'sukses' prepaid bill (billing_status = null, ended_at = null) settled while station is Tersedia
        $legacyExpiredTersedia = Transaction::create([
            'status' => 'user',
            'nama' => 'Legacy Expired Sukses Tersedia',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'harga' => 10000,
            'jam_main' => 2,
            'duration_seconds' => null,
            'waktu_mulai' => '07:00',
            'waktu_Selesai' => '09:00',
            'started_at' => null,
            'expires_at' => null,
            'ended_at' => null,
            'status_transaksi' => 'sukses',
            'billing_status' => null,
            'payment_status' => 'unpaid',
            'total' => 20000,
        ]);

        $invTersedia = $paketService->activatePaidPaket($legacyExpiredTersedia, [
            'payment_method' => 'tunai',
            'amount_paid' => 20000,
            'cashier_user_id' => $this->kasir->id,
        ]);

        $legacyExpiredTersedia->refresh();
        $this->assertSame('paid', $legacyExpiredTersedia->payment_status);
        $this->assertSame('sukses', $legacyExpiredTersedia->status_transaksi);
        $this->assertNull($legacyExpiredTersedia->billing_status);
        $this->assertNull($legacyExpiredTersedia->started_at);
        $this->assertNull($legacyExpiredTersedia->expires_at);
        $this->assertNull($legacyExpiredTersedia->ended_at);
        $this->assertSame('07:00:00', $legacyExpiredTersedia->waktu_mulai);
        $this->assertSame('09:00:00', $legacyExpiredTersedia->waktu_Selesai);
        $this->assertSame('Tersedia', $this->device->fresh()->status);
        $this->assertSame(0, AutomationIntent::where('transaction_id', $legacyExpiredTersedia->id_transaksi)->count());
        $this->assertSame(20000.0, (float) $invTersedia->final_total);

        // 2. Start a new RUNNING session on the station, then settle another legacy/pre-G2 expired 'sukses' prepaid bill via HTTP processPayment
        $newRunning = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'nama' => 'Active Occupant',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);
        $runningStartedAt = $newRunning->started_at->toDateTimeString();
        $intentsBefore = AutomationIntent::count();

        $legacyExpiredOccupied = Transaction::create([
            'status' => 'user',
            'nama' => 'Legacy Expired Sukses Occupied',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'harga' => 10000,
            'jam_main' => 1,
            'duration_seconds' => 3600,
            'waktu_mulai' => '08:00',
            'waktu_Selesai' => '09:00',
            'started_at' => Carbon::parse('2026-11-22 08:00:00'),
            'expires_at' => Carbon::parse('2026-11-22 09:00:00'),
            'ended_at' => null,
            'status_transaksi' => 'sukses',
            'billing_status' => null,
            'payment_status' => 'unpaid',
            'total' => 10000,
        ]);

        $this->actingAs($this->kasir)
            ->post(route('transaction.processPayment', $legacyExpiredOccupied->id_transaksi), [
                'payment_method' => 'tunai',
                'amount_paid' => 10000,
                'discount' => 0,
            ])
            ->assertRedirect(route('transaction.print', ['id' => $legacyExpiredOccupied->id_transaksi, 'action' => 'payment']));

        $legacyExpiredOccupied->refresh();
        $this->assertSame('paid', $legacyExpiredOccupied->payment_status);
        $this->assertSame('sukses', $legacyExpiredOccupied->status_transaksi);
        $this->assertNull($legacyExpiredOccupied->billing_status);
        $this->assertNull($legacyExpiredOccupied->ended_at);
        $this->assertSame('2026-11-22 08:00:00', $legacyExpiredOccupied->started_at->toDateTimeString());
        $this->assertSame('2026-11-22 09:00:00', $legacyExpiredOccupied->expires_at->toDateTimeString());
        $this->assertSame(0, AutomationIntent::where('transaction_id', $legacyExpiredOccupied->id_transaksi)->count());
        $this->assertSame($intentsBefore, AutomationIntent::count());
        $this->assertSame(1, Invoice::where('transaction_id', $legacyExpiredOccupied->id_transaksi)->count());

        // New running session and station remain completely untouched
        $newRunning->refresh();
        $this->assertSame(Transaction::BILLING_RUNNING, $newRunning->billing_status);
        $this->assertSame('unpaid', $newRunning->payment_status);
        $this->assertSame($runningStartedAt, $newRunning->started_at->toDateTimeString());
        $this->assertSame('Digunakan', $this->device->fresh()->status);

        // 3. Also verify legacy/pre-G2 expired 'sukses' custom_package row (billing_status = null, ended_at = null) while occupied
        $customPackage = CustomPackage::create([
            'nama_paket' => 'Legacy Paket Mabar',
            'harga_total' => 18000,
            'is_active' => true,
        ]);
        $customPackage->playstations()->attach($this->playstation->id, ['lama_main' => 120]);

        $legacyCustomOccupied = Transaction::create([
            'status' => 'user',
            'nama' => 'Legacy Expired Custom Sukses',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'custom_package_id' => $customPackage->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'custom_package',
            'harga' => 18000,
            'jam_main' => 120,
            'duration_seconds' => 7200,
            'waktu_mulai' => '06:00',
            'waktu_Selesai' => '08:00',
            'started_at' => Carbon::parse('2026-11-22 06:00:00'),
            'expires_at' => Carbon::parse('2026-11-22 08:00:00'),
            'ended_at' => null,
            'status_transaksi' => 'sukses',
            'billing_status' => null,
            'payment_status' => 'unpaid',
            'total' => 18000,
        ]);

        $invCustom = $paketService->activatePaidPaket($legacyCustomOccupied, [
            'payment_method' => 'tunai',
            'amount_paid' => 18000,
            'cashier_user_id' => $this->kasir->id,
        ]);
        $legacyCustomOccupied->refresh();
        $this->assertSame('paid', $legacyCustomOccupied->payment_status);
        $this->assertNull($legacyCustomOccupied->billing_status);
        $this->assertNull($legacyCustomOccupied->ended_at);
        $this->assertSame(18000.0, (float) $invCustom->final_total);
        $this->assertSame($intentsBefore, AutomationIntent::count());
        $this->assertSame(Transaction::BILLING_RUNNING, $newRunning->fresh()->billing_status);
        $this->assertSame('Digunakan', $this->device->fresh()->status);
    }

    /** @test */
    public function test_finished_postpaid_process_payment_validates_against_checkout_total_and_synthetic_fallback(): void
    {
        $this->actingAs($this->kasir);

        // Case 1: Finished postpaid with playstation + newly added unpaid FnbOrder (total column stale)
        $finishedPostpaid = Transaction::create([
            'status' => 'user',
            'nama' => 'Finished Postpaid With FnB',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'harga' => 10000,
            'waktu_mulai' => '08:00',
            'waktu_Selesai' => '09:00',
            'started_at' => Carbon::parse('2026-11-22 08:00:00'),
            'ended_at' => Carbon::parse('2026-11-22 09:00:00'),
            'status_transaksi' => 'selesai',
            'billing_status' => Transaction::BILLING_COMPLETED,
            'payment_status' => 'unpaid',
            'total' => 10000, // stale before QR FnB order added
        ]);

        $qrOrder = FnbOrder::create([
            'order_number' => 'ORD-POST-ADD-1',
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $this->location->id,
            'transaction_id' => $finishedPostpaid->id_transaksi,
            'customer_name' => $finishedPostpaid->nama,
            'status' => FnbOrder::STATUS_ACCEPTED,
            'payment_status' => 'unpaid',
        ]);
        FnbOrderItem::create([
            'fnb_order_id' => $qrOrder->id,
            'fnb_id' => $this->fnbSnack->id,
            'qty' => 1,
            'unit_price_beli' => 6000,
            'unit_price_jual' => 12000,
            'subtotal' => 12000,
            'payment_status' => 'unpaid',
        ]);

        // Underpayment (10000 < 22000) must fail validation
        $underpay = $this->post(route('transaction.processPayment', $finishedPostpaid->id_transaksi), [
            'payment_method' => 'tunai',
            'amount_paid' => 10000,
        ]);
        $underpay->assertSessionHasErrors('amount_paid');
        $this->assertSame('unpaid', $finishedPostpaid->fresh()->payment_status);

        // Exact checkout total (22000) succeeds
        $okPay = $this->post(route('transaction.processPayment', $finishedPostpaid->id_transaksi), [
            'payment_method' => 'tunai',
            'amount_paid' => 22000,
        ]);
        $okPay->assertRedirect(route('transaction.print', ['id' => $finishedPostpaid->id_transaksi, 'action' => 'payment']));
        $this->assertSame('paid', $finishedPostpaid->fresh()->payment_status);
        $this->assertSame(1, Invoice::where('transaction_id', $finishedPostpaid->id_transaksi)->count());

        // Case 2: Synthetic finished postpaid missing playstation falls back to transaction.total for validation
        $syntheticPostpaid = Transaction::create([
            'status' => 'user',
            'nama' => 'Synthetic No PS',
            'device_id' => null,
            'playstation_id' => null,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'harga' => 0,
            'waktu_mulai' => '08:00',
            'waktu_Selesai' => '09:00',
            'started_at' => Carbon::parse('2026-11-22 08:00:00'),
            'ended_at' => Carbon::parse('2026-11-22 09:00:00'),
            'status_transaksi' => 'selesai',
            'billing_status' => Transaction::BILLING_COMPLETED,
            'payment_status' => 'unpaid',
            'total' => 25000,
        ]);

        $synthUnderpay = $this->post(route('transaction.processPayment', $syntheticPostpaid->id_transaksi), [
            'payment_method' => 'tunai',
            'amount_paid' => 10000,
        ]);
        $synthUnderpay->assertSessionHasErrors('amount_paid');
        $this->assertSame('unpaid', $syntheticPostpaid->fresh()->payment_status);

        // Full exact-amount settlement preserves synthetic total & invoice final_total (NOT zeroed out)
        $synthExactPay = $this->post(route('transaction.processPayment', $syntheticPostpaid->id_transaksi), [
            'payment_method' => 'tunai',
            'amount_paid' => 25000,
            'discount' => 0,
        ]);
        $synthExactPay->assertRedirect(route('transaction.print', ['id' => $syntheticPostpaid->id_transaksi, 'action' => 'payment']));

        $syntheticPostpaid->refresh();
        $this->assertSame('paid', $syntheticPostpaid->payment_status);
        $this->assertSame('selesai', $syntheticPostpaid->status_transaksi);
        $this->assertSame(Transaction::BILLING_COMPLETED, $syntheticPostpaid->billing_status);
        $this->assertSame(25000.0, (float) $syntheticPostpaid->total);

        $synthInvoice = Invoice::with('items')->where('transaction_id', $syntheticPostpaid->id_transaksi)->firstOrFail();
        $this->assertSame(1, Invoice::where('transaction_id', $syntheticPostpaid->id_transaksi)->count());
        $this->assertSame(25000.0, (float) $synthInvoice->subtotal);
        $this->assertSame(25000.0, (float) $synthInvoice->final_total);
        $this->assertSame(25000.0, (float) $synthInvoice->amount_paid);
        $this->assertSame(0.0, (float) $synthInvoice->change_amount);
        $this->assertSame(25000.0, (float) $synthInvoice->items->sum('subtotal'));
    }

    /** @test */
    public function test_dashboard_and_timer_sync_expose_outstanding_unpaid_data_and_keep_status_unchanged(): void
    {
        $this->actingAs($this->kasir);

        $device2 = Device::create([
            'nama' => 'PS5 Station 2',
            'playstation_id' => $this->playstation->id,
            'status' => 'Tersedia',
        ]);
        $device2->playstations()->attach($this->playstation->id);

        // Device 1 has an older unpaid finished bill AND a new active RUNNING session
        $olderUnpaid = Transaction::create([
            'status' => 'user',
            'nama' => 'Older Unpaid Dev1',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'harga' => 10000,
            'jam_main' => 1,
            'duration_seconds' => 3600,
            'waktu_mulai' => '08:00',
            'waktu_Selesai' => '09:00',
            'started_at' => Carbon::parse('2026-11-22 08:00:00'),
            'expires_at' => Carbon::parse('2026-11-22 09:00:00'),
            'ended_at' => Carbon::parse('2026-11-22 09:00:00'),
            'status_transaksi' => 'selesai',
            'billing_status' => Transaction::BILLING_EXPIRED_PENDING_ACTION,
            'payment_status' => 'unpaid',
            'total' => 10000,
        ]);

        $runningOnDev1 = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'nama' => 'Active Dev1',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        // Device 2 is Tersedia with an unpaid STOPPED_EARLY bill and a draft (draft must NOT count in unpaid_attention)
        $stoppedOnDev2 = Transaction::create([
            'status' => 'user',
            'nama' => 'Stopped Dev2',
            'device_id' => $device2->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'harga' => 10000,
            'jam_main' => 2,
            'duration_seconds' => 7200,
            'waktu_mulai' => '08:00',
            'waktu_Selesai' => '08:30',
            'started_at' => Carbon::parse('2026-11-22 08:00:00'),
            'expires_at' => Carbon::parse('2026-11-22 10:00:00'),
            'ended_at' => Carbon::parse('2026-11-22 08:30:00'),
            'status_transaksi' => 'selesai',
            'billing_status' => Transaction::BILLING_STOPPED_EARLY,
            'payment_status' => 'unpaid',
            'total' => 20000,
        ]);

        Transaction::create([
            'status' => 'user',
            'nama' => 'Draft Should Not Count',
            'device_id' => $device2->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'harga' => 10000,
            'jam_main' => 1,
            'waktu_mulai' => '09:00',
            'waktu_Selesai' => '10:00',
            'status_transaksi' => 'draft',
            'billing_status' => Transaction::BILLING_IDLE,
            'payment_status' => 'unpaid',
            'total' => 10000,
        ]);

        // Dashboard viewData
        $dashResp = $this->get(route('transaction.index'))->assertStatus(200);
        $outstandingByDev = $dashResp->viewData('outstandingUnpaidByDevice');
        $outstandingSummaries = $dashResp->viewData('outstandingSummaries');
        $stationStats = $dashResp->viewData('stationStats');

        $this->assertSame(3, $stationStats['unpaid_attention']); // olderUnpaid + runningOnDev1 + stoppedOnDev2 (excludes draft)
        $this->assertSame([$olderUnpaid->id_transaksi], $outstandingByDev->get($this->device->id)->pluck('id_transaksi')->all());
        $this->assertSame([$stoppedOnDev2->id_transaksi], $outstandingByDev->get($device2->id)->pluck('id_transaksi')->all());
        $this->assertSame(10000.0, $outstandingSummaries[$olderUnpaid->id_transaksi]['payable_total']);
        $this->assertSame(20000.0, $outstandingSummaries[$stoppedOnDev2->id_transaksi]['payable_total']);

        // Timer sync endpoint payload
        $timers = $this->getJson(route('device.timers'))->assertStatus(200)->json();
        $dev1Payload = collect($timers['devices'])->firstWhere('id', $this->device->id);
        $dev2Payload = collect($timers['devices'])->firstWhere('id', $device2->id);

        $this->assertSame('Digunakan', $dev1Payload['status']);
        $this->assertSame($runningOnDev1->id_transaksi, $dev1Payload['transaction']['id_transaksi']);
        $this->assertSame([$olderUnpaid->id_transaksi], $dev1Payload['unpaid_transaction_ids']);

        $this->assertSame('Tersedia', $dev2Payload['status']);
        $this->assertNull($dev2Payload['transaction']);
        $this->assertSame([$stoppedOnDev2->id_transaksi], $dev2Payload['unpaid_transaction_ids']);
    }
}
