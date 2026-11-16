<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Fnb;
use App\Models\FnbOrder;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Playstation;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionPosDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $kasir;
    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin.pos@billing.test',
        ]);

        $this->kasir = User::factory()->create([
            'role' => 'kasir',
            'email' => 'kasir.pos@billing.test',
        ]);

        $this->owner = User::factory()->create([
            'role' => 'owner',
            'email' => 'owner.pos@billing.test',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createStation(
        string $name,
        Playstation $playstation,
        string $status = 'Tersedia',
        ?string $locationCode = null
    ): Device {
        $device = Device::create([
            'nama' => $name,
            'playstation_id' => $playstation->id,
            'status' => $status,
        ]);
        $device->playstations()->attach($playstation->id);

        if ($locationCode !== null) {
            Location::create([
                'name' => $name . ' Location',
                'code' => $locationCode,
                'type' => 'station',
                'device_id' => $device->id,
                'is_active' => true,
            ]);
        }

        return $device;
    }

    private function assertSubstringsInStrictOrder(string $html, array $markers): void
    {
        $lastPos = -1;
        foreach ($markers as $marker) {
            $pos = strpos($html, $marker);
            $this->assertNotFalse($pos, "Expected marker '{$marker}' to be present in response.");
            $this->assertGreaterThan(
                $lastPos,
                $pos,
                "Expected marker '{$marker}' to appear after previous marker (pos {$pos} vs {$lastPos})."
            );
            $lastPos = $pos;
        }
    }

    private function extractBootstrapJson(string $html): array
    {
        $matched = preg_match(
            '/<script[^>]*id="pos-bootstrap-data"[^>]*>(.*?)<\/script>/s',
            $html,
            $matches
        );
        $this->assertSame(1, $matched, 'Expected #pos-bootstrap-data JSON script block in HTML.');

        $decoded = json_decode($matches[1], true);
        $this->assertIsArray($decoded, 'Expected valid JSON inside #pos-bootstrap-data.');

        return $decoded;
    }

    /** @test */
    public function test_deterministic_station_ordering_unified_and_stable_across_status_changes(): void
    {
        $ps3 = Playstation::create(['nama' => 'PS3 Reguler', 'harga' => 6000]);
        $ps4 = Playstation::create(['nama' => 'PS4 Pro', 'harga' => 10000]);
        $billiard = Playstation::create(['nama' => 'Billiard Table', 'harga' => 25000]);

        // Non-sequential DB insertion order
        $d12 = $this->createStation('PS4 12', $ps4);
        $d3 = $this->createStation('Meja 3 - Billiard', $billiard);
        $d10 = $this->createStation('PS4 VIP', $ps4, 'Tersedia', 'STATION 10');
        $d1 = $this->createStation('PS3 1', $ps3);
        $d2 = $this->createStation('PS4 Reguler', $ps4, 'Tersedia', 'STATION 02');

        $expectedOrder = [$d1->id, $d2->id, $d3->id, $d10->id, $d12->id];

        // 1. Model-level deterministic sorting
        $sorted = Device::sortStationsDeterministically(
            Device::with(['playstations', 'locations'])->get()
        );
        $this->assertSame($expectedOrder, $sorted->pluck('id')->all());

        // 2. /transaction renders 1, 2, 3, 10, 12 in strict numeric order
        $txResp = $this->actingAs($this->kasir)->get(route('transaction.index'));
        $txResp->assertStatus(200);
        $this->assertSubstringsInStrictOrder(
            $txResp->getContent(),
            array_map(fn ($id) => 'id="station-card-' . $id . '"', $expectedOrder)
        );
        $bootstrap = $this->extractBootstrapJson($txResp->getContent());
        $this->assertSame(
            [1, 2, 3, 10, 12],
            array_column($bootstrap['devices'], 'station_number')
        );

        // 3. /device renders in exact deterministic order
        $devResp = $this->actingAs($this->kasir)->get(route('device.index'));
        $devResp->assertStatus(200);
        $this->assertSubstringsInStrictOrder(
            $devResp->getContent(),
            array_map(fn ($id) => 'id="status-' . $id . '"', $expectedOrder)
        );

        // 4. Flip station #10 to Digunakan with active running transaction + newer completed tx on #1
        $d10->update(['status' => 'Digunakan']);
        Transaction::create([
            'status' => 'user',
            'nama' => 'Pelanggan 10',
            'device_id' => $d10->id,
            'playstation_id' => $ps4->id,
            'user_id' => $this->kasir->id,
            'harga' => $ps4->harga,
            'waktu_mulai' => now()->subMinutes(15)->format('H:i'),
            'status_transaksi' => 'berjalan',
            'payment_status' => 'unpaid',
            'tipe_transaksi' => 'postpaid',
            'lost_time_start' => now()->subMinutes(15),
            'started_at' => now()->subMinutes(15),
            'billing_status' => Transaction::BILLING_RUNNING,
            'total' => 0,
        ]);
        Transaction::create([
            'status' => 'user',
            'nama' => 'Pelanggan 1 Selesai',
            'device_id' => $d1->id,
            'playstation_id' => $ps3->id,
            'user_id' => $this->kasir->id,
            'harga' => $ps3->harga,
            'waktu_mulai' => now()->subMinutes(5)->format('H:i'),
            'waktu_Selesai' => now()->format('H:i'),
            'status_transaksi' => 'selesai',
            'payment_status' => 'paid',
            'tipe_transaksi' => 'postpaid',
            'billing_status' => Transaction::BILLING_COMPLETED,
            'total' => 6000,
        ]);

        // Order remains unchanged on both /transaction and /device
        $txRespAfter = $this->actingAs($this->kasir)->get(route('transaction.index'));
        $txRespAfter->assertStatus(200);
        $this->assertSubstringsInStrictOrder(
            $txRespAfter->getContent(),
            array_map(fn ($id) => 'id="station-card-' . $id . '"', $expectedOrder)
        );

        $devRespAfter = $this->actingAs($this->kasir)->get(route('device.index'));
        $devRespAfter->assertStatus(200);
        $this->assertSubstringsInStrictOrder(
            $devRespAfter->getContent(),
            array_map(fn ($id) => 'id="status-' . $id . '"', $expectedOrder)
        );
    }

    /** @test */
    public function test_anonymous_quick_lost_time_and_paket_and_legacy_create_fallback(): void
    {
        $ps4 = Playstation::create(['nama' => 'PS4 Station', 'harga' => 10000]);
        $dev1 = $this->createStation('PS4 1', $ps4);
        $dev2 = $this->createStation('PS4 2', $ps4);
        $dev3 = $this->createStation('PS4 3', $ps4);

        // 1. Anonymous Lost Time via JSON
        $ltResp = $this->actingAs($this->kasir)->postJson(route('transaction.store'), [
            'tipe_transaksi' => 'postpaid',
            'device_id' => $dev1->id,
            'playstation_id' => $ps4->id,
            'nama' => '',
        ]);

        $ltResp->assertStatus(201)->assertJson(['success' => true]);
        $ltTxId = $ltResp->json('transaction_id');

        $this->assertDatabaseHas('transactions', [
            'id_transaksi' => $ltTxId,
            'nama' => 'Pelanggan',
            'tipe_transaksi' => 'postpaid',
            'billing_status' => Transaction::BILLING_RUNNING,
            'status_transaksi' => 'berjalan',
            'payment_status' => 'unpaid',
        ]);
        $this->assertSame('Digunakan', $dev1->fresh()->status);

        // 2. Anonymous Paket via JSON — keeps device Tersedia until payment
        $paketResp = $this->actingAs($this->kasir)->postJson(route('transaction.store'), [
            'tipe_transaksi' => 'prepaid',
            'device_id' => $dev2->id,
            'playstation_id' => $ps4->id,
            'duration_seconds' => 3600,
            'harga' => 10000,
            'total' => 10000,
            'nama' => '',
        ]);

        $paketResp->assertStatus(201)->assertJson(['success' => true]);
        $paketTxId = $paketResp->json('transaction_id');
        $this->assertSame(
            route('transaction.showPayment', $paketTxId),
            $paketResp->json('redirect_url')
        );

        $this->assertDatabaseHas('transactions', [
            'id_transaksi' => $paketTxId,
            'nama' => 'Pelanggan',
            'tipe_transaksi' => 'prepaid',
            'status_transaksi' => 'draft',
            'payment_status' => 'unpaid',
            'billing_status' => Transaction::BILLING_IDLE,
        ]);
        $this->assertSame('Tersedia', $dev2->fresh()->status);

        // 3. /transaction/create fallback and traditional form POST with customer info
        $this->actingAs($this->kasir)
            ->get(route('transaction.create'))
            ->assertStatus(200);

        $legacyResp = $this->actingAs($this->kasir)->post(route('transaction.store'), [
            'tipe_transaksi' => 'postpaid',
            'device_id' => $dev3->id,
            'playstation_id' => $ps4->id,
            'nama' => 'Budi Santoso',
            'no_telepon' => '081234567890',
        ]);

        $legacyResp->assertRedirect(route('transaction.index'));
        $this->assertDatabaseHas('transactions', [
            'device_id' => $dev3->id,
            'nama' => 'Budi Santoso',
            'no_telepon' => '081234567890',
            'tipe_transaksi' => 'postpaid',
        ]);
        $this->assertSame('Digunakan', $dev3->fresh()->status);
    }

    /** @test */
    public function test_multi_category_device_requires_playstation_selection(): void
    {
        $ps4 = Playstation::create(['nama' => 'PS4', 'harga' => 8000]);
        $ps5 = Playstation::create(['nama' => 'PS5', 'harga' => 15000]);

        $comboDevice = Device::create([
            'nama' => 'PS Combo 1',
            'playstation_id' => $ps4->id,
            'status' => 'Tersedia',
        ]);
        $comboDevice->playstations()->attach([$ps4->id, $ps5->id]);

        // Without playstation_id -> 422 JSON error, device stays Tersedia
        $failResp = $this->actingAs($this->kasir)->postJson(route('transaction.store'), [
            'device_id' => $comboDevice->id,
            'tipe_transaksi' => 'postpaid',
        ]);

        $failResp->assertStatus(422)->assertJson(['success' => false]);
        $this->assertSame('Tersedia', $comboDevice->fresh()->status);

        // With valid playstation_id -> 201 and stores chosen playstation_id
        $okResp = $this->actingAs($this->kasir)->postJson(route('transaction.store'), [
            'device_id' => $comboDevice->id,
            'playstation_id' => $ps5->id,
            'tipe_transaksi' => 'postpaid',
        ]);

        $okResp->assertStatus(201)->assertJson(['success' => true]);
        $this->assertDatabaseHas('transactions', [
            'id_transaksi' => $okResp->json('transaction_id'),
            'device_id' => $comboDevice->id,
            'playstation_id' => $ps5->id,
        ]);
        $this->assertSame('Digunakan', $comboDevice->fresh()->status);
    }

    /** @test */
    public function test_paket_payment_lifecycle_and_double_payment_guard(): void
    {
        $ps4 = Playstation::create(['nama' => 'PS4 Reg', 'harga' => 10000]);
        $device = $this->createStation('PS4 4', $ps4);

        $createResp = $this->actingAs($this->kasir)->postJson(route('transaction.store'), [
            'tipe_transaksi' => 'prepaid',
            'device_id' => $device->id,
            'playstation_id' => $ps4->id,
            'duration_seconds' => 3600,
            'harga' => 10000,
            'total' => 10000,
            'nama' => '',
        ]);

        $createResp->assertStatus(201);
        $txId = $createResp->json('transaction_id');
        $this->assertSame('Tersedia', $device->fresh()->status);

        // Complete payment
        $payResp = $this->actingAs($this->kasir)->post(
            route('transaction.processPayment', $txId),
            [
                'payment_method' => 'tunai',
                'amount_paid' => 10000,
            ]
        );

        $payResp->assertRedirect(route('transaction.print', ['id' => $txId, 'action' => 'payment']));

        $tx = Transaction::findOrFail($txId);
        $this->assertSame('paid', $tx->payment_status);
        $this->assertSame(Transaction::BILLING_RUNNING, $tx->billing_status);
        $this->assertSame('Digunakan', $device->fresh()->status);
        $this->assertSame(1, Invoice::where('transaction_id', $txId)->count());

        // Second payment attempt must redirect & block duplicate payment
        $secondPay = $this->actingAs($this->kasir)->post(
            route('transaction.processPayment', $txId),
            [
                'payment_method' => 'tunai',
                'amount_paid' => 10000,
            ]
        );

        $secondPay->assertRedirect(route('transaction.print', $txId));
        $secondPay->assertSessionHas('gagal', 'Transaksi ini sudah lunas.');
        $this->assertSame(1, Invoice::where('transaction_id', $txId)->count());
    }

    /** @test */
    public function test_active_card_rendering_and_lost_time_berhenti_vs_selesai(): void
    {
        $ps4 = Playstation::create(['nama' => 'PS4', 'harga' => 12000]);
        $devStop = $this->createStation('PS4 5', $ps4);
        $devEnd = $this->createStation('PS4 6', $ps4);

        $baseNow = Carbon::today()->setHour(10)->setMinute(0)->setSecond(0);
        Carbon::setTestNow($baseNow);

        $lt1Resp = $this->actingAs($this->kasir)->postJson(route('transaction.store'), [
            'tipe_transaksi' => 'postpaid',
            'device_id' => $devStop->id,
            'playstation_id' => $ps4->id,
        ]);
        $lt1Resp->assertStatus(201);
        $txStopId = $lt1Resp->json('transaction_id');

        // Verify active card rendering and dynamic links
        $indexResp = $this->actingAs($this->kasir)->get(route('transaction.index'));
        $indexResp->assertStatus(200);
        $indexResp->assertSee('href="' . route('transaction.edit', $txStopId) . '"', false);
        $indexResp->assertSee('href="' . route('transaction.showPayment', $txStopId) . '"', false);
        $indexResp->assertSee('js-open-detail', false);
        $indexResp->assertSee('js-open-cafe', false);
        $indexResp->assertSee('js-open-berhenti', false);
        $indexResp->assertSee('js-open-selesai', false);
        $indexResp->assertSee('data-action="' . route('transaction.end', $txStopId) . '"', false);

        // Berhenti: POST /device/{deviceId}/stop returns 200 JSON, frees device, stays unpaid, no payment redirect
        $stopResp = $this->actingAs($this->kasir)->postJson(route('device.stop', $devStop->id));
        $stopResp->assertStatus(200)
            ->assertJson([
                'success' => true,
                'new_status' => 'Tersedia',
                'transaction_id' => $txStopId,
            ]);
        $this->assertArrayNotHasKey('redirect_url', $stopResp->json());

        $txStop = Transaction::findOrFail($txStopId);
        $this->assertSame('Tersedia', $devStop->fresh()->status);
        $this->assertSame('selesai', $txStop->status_transaksi);
        $this->assertSame(Transaction::BILLING_COMPLETED, $txStop->billing_status);
        $this->assertSame('unpaid', $txStop->payment_status);

        // Selesai: POST /transaction/{txId}/end computes duration & total and redirects to payment
        $lt2Resp = $this->actingAs($this->kasir)->postJson(route('transaction.store'), [
            'tipe_transaksi' => 'postpaid',
            'device_id' => $devEnd->id,
            'playstation_id' => $ps4->id,
        ]);
        $lt2Resp->assertStatus(201);
        $txEndId = $lt2Resp->json('transaction_id');

        Carbon::setTestNow($baseNow->copy()->addMinutes(65));

        $endResp = $this->actingAs($this->kasir)->post(route('transaction.end', $txEndId));
        $endResp->assertRedirect(route('transaction.showPayment', $txEndId));

        $txEnd = Transaction::findOrFail($txEndId);
        $this->assertSame('Tersedia', $devEnd->fresh()->status);
        $this->assertSame('selesai', $txEnd->status_transaksi);
        $this->assertSame(Transaction::BILLING_COMPLETED, $txEnd->billing_status);
        $this->assertSame('unpaid', $txEnd->payment_status);
        $this->assertSame('1 jam 5 menit', $txEnd->jam_main);
        $this->assertGreaterThan(0, (float) $txEnd->total);
    }

    /** @test */
    public function test_cafe_fnb_integration_for_lost_time_paid_paket_and_standalone(): void
    {
        $ps4 = Playstation::create(['nama' => 'PS4', 'harga' => 10000]);
        $dev1 = $this->createStation('PS4 7', $ps4, 'Tersedia', 'STATION 07');
        $dev2 = $this->createStation('PS4 8', $ps4, 'Tersedia', 'STATION 08');

        $fnb = Fnb::create([
            'nama' => 'Es Teh Manis',
            'harga_beli' => 2000,
            'harga_jual' => 5000,
            'stok' => 25,
        ]);

        // 1. Active Lost Time session + FnB order (save)
        $ltResp = $this->actingAs($this->kasir)->postJson(route('transaction.store'), [
            'tipe_transaksi' => 'postpaid',
            'device_id' => $dev1->id,
            'playstation_id' => $ps4->id,
        ]);
        $ltTxId = $ltResp->json('transaction_id');

        $fnbLtResp = $this->actingAs($this->kasir)->postJson(route('fnb.orders.store'), [
            'transaction_id' => $ltTxId,
            'action' => 'save',
            'items' => [
                ['fnb_id' => $fnb->id, 'qty' => 2],
            ],
        ]);

        $fnbLtResp->assertStatus(201)->assertJson(['success' => true]);
        $this->assertDatabaseHas('fnb_orders', [
            'transaction_id' => $ltTxId,
            'payment_status' => 'unpaid',
        ]);

        $dashResp = $this->actingAs($this->kasir)->get(route('transaction.index'));
        $dashResp->assertStatus(200);
        $dashResp->assertSee('Rp 10.000');

        $bootstrap = $this->extractBootstrapJson($dashResp->getContent());
        $activeDev1 = $bootstrap['activeTransactions'][(string) $dev1->id] ?? null;
        $this->assertNotNull($activeDev1);
        $this->assertEquals(10000, $activeDev1['fnb_unpaid_total']);
        $this->assertCount(1, $activeDev1['fnb_items']);
        $this->assertSame('Es Teh Manis', $activeDev1['fnb_items'][0]['nama']);

        // 2. Active Paid Paket session + FnB order (must not reject due to paid rental status)
        $paketResp = $this->actingAs($this->kasir)->postJson(route('transaction.store'), [
            'tipe_transaksi' => 'prepaid',
            'device_id' => $dev2->id,
            'playstation_id' => $ps4->id,
            'duration_seconds' => 3600,
            'harga' => 10000,
            'total' => 10000,
        ]);
        $paketTxId = $paketResp->json('transaction_id');

        $this->actingAs($this->kasir)->post(route('transaction.processPayment', $paketTxId), [
            'payment_method' => 'tunai',
            'amount_paid' => 10000,
        ]);

        $this->assertSame('paid', Transaction::findOrFail($paketTxId)->payment_status);

        $fnbPaketResp = $this->actingAs($this->kasir)->postJson(route('fnb.orders.store'), [
            'transaction_id' => $paketTxId,
            'action' => 'save',
            'items' => [
                ['fnb_id' => $fnb->id, 'qty' => 1],
            ],
        ]);
        $fnbPaketResp->assertStatus(201)->assertJson(['success' => true]);
        $this->assertDatabaseHas('fnb_orders', [
            'transaction_id' => $paketTxId,
            'payment_status' => 'unpaid',
        ]);

        // 3. Standalone non-playing Cafe order with immediate payment
        $standaloneResp = $this->actingAs($this->kasir)->postJson(route('fnb.orders.store'), [
            'transaction_id' => null,
            'customer_name' => 'Tamu Cafe',
            'action' => 'pay_now',
            'payment_method' => 'tunai',
            'amount_paid' => 15000,
            'items' => [
                ['fnb_id' => $fnb->id, 'qty' => 3],
            ],
        ]);

        $standaloneResp->assertStatus(201)->assertJson(['success' => true]);
        $invoiceId = $standaloneResp->json('invoice.id');
        $this->assertNotNull($invoiceId);
        $this->assertSame(
            route('fnb.invoice.show', $invoiceId),
            $standaloneResp->json('redirect_url')
        );
        $this->assertDatabaseHas('fnb_orders', [
            'id' => $standaloneResp->json('order.id'),
            'transaction_id' => null,
            'payment_status' => 'paid',
        ]);
        $this->assertDatabaseHas('invoices', [
            'id' => $invoiceId,
            'invoice_type' => Invoice::TYPE_FNB_STANDALONE,
            'final_total' => 15000,
        ]);
    }

    /** @test */
    public function test_history_tab_search_and_filter_independence(): void
    {
        $ps4 = Playstation::create(['nama' => 'PS4', 'harga' => 10000]);

        // Insert out of order
        $d12 = $this->createStation('PS4 12', $ps4);
        $d1 = $this->createStation('PS4 1', $ps4);
        $d5 = $this->createStation('PS4 5', $ps4);

        Transaction::create([
            'status' => 'user',
            'nama' => 'Andi LostTime',
            'device_id' => $d1->id,
            'playstation_id' => $ps4->id,
            'user_id' => $this->kasir->id,
            'harga' => 10000,
            'waktu_mulai' => '10:00',
            'waktu_Selesai' => '11:00',
            'status_transaksi' => 'selesai',
            'payment_status' => 'paid',
            'tipe_transaksi' => 'postpaid',
            'billing_status' => Transaction::BILLING_COMPLETED,
            'total' => 10000,
        ]);

        Transaction::create([
            'status' => 'user',
            'nama' => 'Budi Paket',
            'device_id' => $d5->id,
            'playstation_id' => $ps4->id,
            'user_id' => $this->kasir->id,
            'harga' => 10000,
            'jam_main' => '01:00:00',
            'duration_seconds' => 3600,
            'waktu_mulai' => '10:00',
            'waktu_Selesai' => '11:00',
            'status_transaksi' => 'selesai',
            'payment_status' => 'paid',
            'tipe_transaksi' => 'prepaid',
            'billing_status' => Transaction::BILLING_COMPLETED,
            'total' => 10000,
        ]);

        Transaction::create([
            'status' => 'user',
            'nama' => 'Citra Other',
            'device_id' => $d12->id,
            'playstation_id' => $ps4->id,
            'user_id' => $this->kasir->id,
            'harga' => 10000,
            'waktu_mulai' => '10:00',
            'waktu_Selesai' => '11:00',
            'status_transaksi' => 'selesai',
            'payment_status' => 'paid',
            'tipe_transaksi' => 'postpaid',
            'billing_status' => Transaction::BILLING_COMPLETED,
            'total' => 10000,
        ]);

        $resp = $this->actingAs($this->kasir)->get(
            route('transaction.index', [
                'tab' => 'history',
                'search' => 'Andi',
                'type' => 'postpaid',
            ])
        );

        $resp->assertStatus(200);
        $html = $resp->getContent();

        // History tab is active and filtered table body contains only Andi LostTime
        $this->assertMatchesRegularExpression('/id="tab-history"[^>]*aria-selected="true"/s', $html);
        $matched = preg_match(
            '/<table[^>]*>.*?<tbody>(.*?)<\/tbody>/s',
            $html,
            $tbodyMatches
        );
        $this->assertSame(1, $matched, 'Expected history table tbody in HTML.');
        $tbody = $tbodyMatches[1];

        $this->assertStringContainsString('Andi LostTime', $tbody);
        $this->assertStringNotContainsString('Budi Paket', $tbody);
        $this->assertStringNotContainsString('Citra Other', $tbody);

        // Station cards remain complete and in deterministic 1, 5, 12 order
        $this->assertSubstringsInStrictOrder($html, [
            'id="station-card-' . $d1->id . '"',
            'id="station-card-' . $d5->id . '"',
            'id="station-card-' . $d12->id . '"',
        ]);
    }

    /** @test */
    public function test_rbac_enforcement_and_device_hardware_focus(): void
    {
        $ps4 = Playstation::create(['nama' => 'PS4', 'harga' => 10000]);
        $devAvailable = $this->createStation('PS4 1', $ps4, 'Tersedia');
        $devBusy = $this->createStation('PS4 2', $ps4, 'Digunakan');

        $activeTx = Transaction::create([
            'status' => 'user',
            'nama' => 'Pelanggan Aktif',
            'device_id' => $devBusy->id,
            'playstation_id' => $ps4->id,
            'user_id' => $this->kasir->id,
            'harga' => 10000,
            'waktu_mulai' => now()->subMinutes(10)->format('H:i'),
            'status_transaksi' => 'berjalan',
            'payment_status' => 'unpaid',
            'tipe_transaksi' => 'postpaid',
            'lost_time_start' => now()->subMinutes(10),
            'started_at' => now()->subMinutes(10),
            'billing_status' => Transaction::BILLING_RUNNING,
            'total' => 0,
        ]);

        // 1. Owner on GET /transaction: read-only monitoring
        $ownerDash = $this->actingAs($this->owner)->get(route('transaction.index'));
        $ownerDash->assertStatus(200);
        $ownerDash->assertSee('id="station-card-' . $devAvailable->id . '"', false);
        $ownerDash->assertSee('id="station-card-' . $devBusy->id . '"', false);
        $ownerDash->assertSee('js-open-detail', false);
        $ownerDash->assertSee('Mode Pantau (Read-Only)');
        $ownerDash->assertDontSee('js-start-lost-time', false);
        $ownerDash->assertDontSee('js-open-paket', false);
        $ownerDash->assertDontSee('js-open-berhenti', false);
        $ownerDash->assertDontSee('js-open-selesai', false);
        $ownerDash->assertDontSee(route('transaction.showPayment', $activeTx->id_transaksi), false);
        $ownerDash->assertDontSee(route('transaction.edit', $activeTx->id_transaksi), false);

        // Owner mutations blocked with 403
        $this->actingAs($this->owner)
            ->postJson(route('transaction.store'), [
                'tipe_transaksi' => 'postpaid',
                'device_id' => $devAvailable->id,
                'playstation_id' => $ps4->id,
            ])
            ->assertStatus(403);

        $this->actingAs($this->owner)
            ->postJson(route('device.stop', $devBusy->id))
            ->assertStatus(403);

        // 2. Kasir sees operational controls, but DELETE /transaction/{id} is 403
        $kasirDash = $this->actingAs($this->kasir)->get(route('transaction.index'));
        $kasirDash->assertStatus(200);
        $kasirDash->assertSee('js-start-lost-time', false);
        $kasirDash->assertSee('js-open-paket', false);
        $kasirDash->assertSee('js-open-berhenti', false);
        $kasirDash->assertSee('js-open-selesai', false);

        $this->actingAs($this->kasir)
            ->delete(route('transaction.destroy', $activeTx->id_transaksi))
            ->assertStatus(403);

        // 3. /device focuses on hardware monitoring & links to /transaction without POS billing modals
        foreach ([$this->kasir, $this->admin] as $user) {
            $devResp = $this->actingAs($user)->get(route('device.index'));
            $devResp->assertStatus(200);
            $devResp->assertSee('Monitoring & Kontrol Hardware Perangkat', false);
            $devResp->assertSee('Kelola di Kasir');
            $devResp->assertSee('href="' . route('transaction.index') . '"', false);
            $devResp->assertDontSee('stopDeviceModal', false);
            $devResp->assertDontSee('finishTransactionModal', false);
            $devResp->assertDontSee('customerModal', false);
        }
    }
}
