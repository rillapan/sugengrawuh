<?php

namespace Tests\Feature\Receipt;

use Tests\TestCase;
use App\Models\User;
use App\Models\Device;
use App\Models\Playstation;
use App\Models\HourlyPrice;
use App\Models\Transaction;
use App\Models\Location;
use App\Models\Fnb;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ThermalReceiptPrintTest extends TestCase
{
    use RefreshDatabase;

    protected $kasirA;
    protected $kasirB;
    protected $playstation;
    protected $device;
    protected $location;
    protected $fnbIndomie;
    protected $fnbTeh;
    protected $fnbKopi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kasirA = User::factory()->create([
            'name' => 'Budi Kasir Pagi',
            'role' => 'kasir',
            'email' => 'budi@sugengrawuh.test',
        ]);

        $this->kasirB = User::factory()->create([
            'name' => 'Siti Kasir Malam',
            'role' => 'kasir',
            'email' => 'siti@sugengrawuh.test',
        ]);

        $this->playstation = Playstation::create([
            'nama' => 'PS5 VIP',
            'harga' => 15000,
        ]);

        HourlyPrice::create([
            'playstation_id' => $this->playstation->id,
            'hours' => 1,
            'price' => 15000,
        ]);
        HourlyPrice::create([
            'playstation_id' => $this->playstation->id,
            'hours' => 2,
            'price' => 30000,
        ]);

        $this->device = Device::create([
            'nama' => 'PS5-VIP-01',
            'playstation_id' => $this->playstation->id,
            'status' => 'Tersedia',
        ]);

        $this->location = Location::create([
            'name' => 'Station VIP 1',
            'code' => 'VIP-01',
            'device_id' => $this->device->id,
        ]);

        $this->fnbIndomie = Fnb::create([
            'nama' => 'Indomie Goreng',
            'harga_beli' => 4000,
            'harga_jual' => 8000,
            'stok' => 50,
            'kategori' => 'makanan',
        ]);

        $this->fnbTeh = Fnb::create([
            'nama' => 'Es Teh Manis',
            'harga_beli' => 1500,
            'harga_jual' => 4000,
            'stok' => 50,
            'kategori' => 'minuman',
        ]);

        $this->fnbKopi = Fnb::create([
            'nama' => 'Kopi Tubruk',
            'harga_beli' => 2500,
            'harga_jual' => 5000,
            'stok' => 50,
            'kategori' => 'minuman',
        ]);
    }

    protected function createSampleTransaction(array $attributes = []): Transaction
    {
        $default = [
            'status' => 'user',
            'nama' => 'Pelanggan Test',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasirA->id,
            'harga' => 15000,
            'total' => 15000,
            'jam_main' => '1 Jam',
            'waktu_mulai' => '10:00:00',
            'waktu_Selesai' => '11:00:00',
            'status_transaksi' => 'selesai',
            'billing_status' => Transaction::BILLING_COMPLETED,
            'payment_status' => 'paid',
            'payment_method' => 'tunai',
            'tipe_transaksi' => 'prepaid',
            'created_at' => now(),
            'paid_at' => now(),
        ];

        return Transaction::create(array_merge($default, $attributes));
    }

    /**
     * Test 1: Struk F&B satu item.
     */
    public function test_struk_fnb_satu_item(): void
    {
        $now = Carbon::parse('2026-11-22 14:00:00');
        $invoice = Invoice::create([
            'invoice_number' => 'INV-20261122-FNB01',
            'transaction_id' => null,
            'invoice_type' => Invoice::TYPE_FNB_STANDALONE,
            'subtotal' => 8000,
            'discount' => 0,
            'final_total' => 8000,
            'amount_paid' => 10000,
            'change_amount' => 2000,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->kasirA->id,
            'paid_at' => $now,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_type' => InvoiceItem::ITEM_TYPE_FNB_ITEM,
            'fnb_id' => $this->fnbIndomie->id,
            'name' => $this->fnbIndomie->nama,
            'qty' => 1,
            'unit_price_beli' => 4000,
            'unit_price_jual' => 8000,
            'subtotal' => 8000,
        ]);

        $response = $this->actingAs($this->kasirA)->get(route('fnb.invoice.show', $invoice->id));

        $response->assertStatus(200);
        $response->assertSee('Sugeng Rawuh');
        $response->assertSee('INV-20261122-FNB01');
        $response->assertSee('Budi Kasir Pagi');
        $response->assertSee('22/11/2026 14:00');
        $response->assertSee('Indomie Goreng');
        $response->assertSee('1 x Rp 8.000');
        $response->assertSee('Rp 8.000');
        $response->assertSee('Rp 10.000'); // bayar
        $response->assertSee('Rp 2.000'); // kembali
        $response->assertSee('size: 80mm auto', false);
        $response->assertSee('receipt-container', false);
        $response->assertSee('receipt-body', false);
    }

    /**
     * Test 2: Struk F&B multi-item.
     */
    public function test_struk_fnb_multi_item(): void
    {
        $now = Carbon::parse('2026-11-22 15:30:00');
        $invoice = Invoice::create([
            'invoice_number' => 'INV-20261122-MULTI',
            'transaction_id' => null,
            'invoice_type' => Invoice::TYPE_FNB_STANDALONE,
            'subtotal' => 29000,
            'discount' => 0,
            'final_total' => 29000,
            'amount_paid' => 50000,
            'change_amount' => 21000,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->kasirA->id,
            'paid_at' => $now,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_type' => InvoiceItem::ITEM_TYPE_FNB_ITEM,
            'fnb_id' => $this->fnbIndomie->id,
            'name' => 'Indomie Goreng',
            'qty' => 2,
            'unit_price_beli' => 4000,
            'unit_price_jual' => 8000,
            'subtotal' => 16000,
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_type' => InvoiceItem::ITEM_TYPE_FNB_ITEM,
            'fnb_id' => $this->fnbTeh->id,
            'name' => 'Es Teh Manis',
            'qty' => 2,
            'unit_price_beli' => 1500,
            'unit_price_jual' => 4000,
            'subtotal' => 8000,
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_type' => InvoiceItem::ITEM_TYPE_FNB_ITEM,
            'fnb_id' => $this->fnbKopi->id,
            'name' => 'Kopi Tubruk',
            'qty' => 1,
            'unit_price_beli' => 2500,
            'unit_price_jual' => 5000,
            'subtotal' => 5000,
        ]);

        $response = $this->actingAs($this->kasirA)->get(route('fnb.invoice.show', $invoice->id));

        $response->assertStatus(200);
        $response->assertSee('2 x Rp 8.000');
        $response->assertSee('Rp 16.000');
        $response->assertSee('2 x Rp 4.000');
        $response->assertSee('Rp 8.000');
        $response->assertSee('1 x Rp 5.000');
        $response->assertSee('Rp 5.000');
        $response->assertSee('Rp 29.000'); // total
        $response->assertSee('Rp 50.000'); // bayar
        $response->assertSee('Rp 21.000'); // kembali
    }

    /**
     * Test 3: Struk Billing Lost Time.
     */
    public function test_struk_billing_lost_time(): void
    {
        $startTime = Carbon::parse('2026-11-22 10:00:00');
        $endTime = Carbon::parse('2026-11-22 11:30:00');

        $transaction = $this->createSampleTransaction([
            'nama' => 'Pelanggan Lost Time',
            'total' => 22500,
            'tipe_transaksi' => 'postpaid',
            'start_time' => $startTime,
            'end_time' => $endTime,
            'jam_main' => '01:30:00',
            'created_at' => $startTime,
            'paid_at' => $endTime,
        ]);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-20261122-LT01',
            'transaction_id' => $transaction->id_transaksi,
            'invoice_type' => Invoice::TYPE_GAME_ONLY,
            'subtotal' => 22500,
            'discount' => 0,
            'final_total' => 22500,
            'amount_paid' => 30000,
            'change_amount' => 7500,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->kasirA->id,
            'paid_at' => $endTime,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_type' => InvoiceItem::ITEM_TYPE_GAME_RENTAL,
            'name' => 'Rental PS5-VIP-01 (Lost Time)',
            'qty' => 1,
            'unit_price_beli' => 0,
            'unit_price_jual' => 22500,
            'subtotal' => 22500,
        ]);

        $response = $this->actingAs($this->kasirA)->get(route('transaction.print', $transaction->id_transaksi));

        $response->assertStatus(200);
        $response->assertSee('Pelanggan Lost Time');
        $response->assertSee('PS5 VIP');
        $response->assertSee('01:30:00 (Lost Time)');
        $response->assertSee('Rp 22.500');
        $response->assertSee('Rp 30.000');
        $response->assertSee('Rp 7.500');
        $response->assertSee('Budi Kasir Pagi');
        $response->assertSee('22/11/2026 11:30');
    }

    /**
     * Test 4: Struk Billing Timer.
     */
    public function test_struk_billing_timer(): void
    {
        $startTime = Carbon::parse('2026-11-22 13:00:00');
        $endTime = Carbon::parse('2026-11-22 15:00:00');

        $transaction = $this->createSampleTransaction([
            'nama' => 'Pelanggan Timer',
            'total' => 30000,
            'tipe_transaksi' => 'prepaid',
            'start_time' => $startTime,
            'end_time' => $endTime,
            'jam_main' => '2 Jam',
            'created_at' => $startTime,
            'paid_at' => $startTime,
        ]);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-20261122-TIMER01',
            'transaction_id' => $transaction->id_transaksi,
            'invoice_type' => Invoice::TYPE_GAME_ONLY,
            'subtotal' => 30000,
            'discount' => 0,
            'final_total' => 30000,
            'amount_paid' => 50000,
            'change_amount' => 20000,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->kasirA->id,
            'paid_at' => $startTime,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_type' => InvoiceItem::ITEM_TYPE_GAME_RENTAL,
            'name' => 'Rental PS5-VIP-01 (2 Jam)',
            'qty' => 1,
            'unit_price_beli' => 0,
            'unit_price_jual' => 30000,
            'subtotal' => 30000,
        ]);

        $response = $this->actingAs($this->kasirA)->get(route('transaction.print', $transaction->id_transaksi));

        $response->assertStatus(200);
        $response->assertSee('Pelanggan Timer');
        $response->assertSee('2 Jam');
        $response->assertSee('Rp 30.000');
        $response->assertSee('Rp 50.000');
        $response->assertSee('Rp 20.000');
    }

    /**
     * Test 5: Nama item panjang tidak menimpa kolom Qty / Subtotal.
     */
    public function test_nama_item_panjang(): void
    {
        $longName = 'Indomie Goreng Spesial Jumbo Double Telur + Keju Mozzarella + Sosis Bakar Extra Pedas Super Lezat';
        $invoice = Invoice::create([
            'invoice_number' => 'INV-20261122-LONGNAME',
            'transaction_id' => null,
            'invoice_type' => Invoice::TYPE_FNB_STANDALONE,
            'subtotal' => 25000,
            'discount' => 0,
            'final_total' => 25000,
            'amount_paid' => 50000,
            'change_amount' => 25000,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->kasirA->id,
            'paid_at' => now(),
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_type' => InvoiceItem::ITEM_TYPE_FNB_ITEM,
            'fnb_id' => $this->fnbIndomie->id,
            'name' => $longName,
            'qty' => 1,
            'unit_price_beli' => 15000,
            'unit_price_jual' => 25000,
            'subtotal' => 25000,
        ]);

        $response = $this->actingAs($this->kasirA)->get(route('fnb.invoice.show', $invoice->id));

        $response->assertStatus(200);
        $response->assertSee($longName);
        $response->assertSee('item-name', false);
        $response->assertSee('colspan="2"', false);
        $response->assertSee('item-calc', false);
        $response->assertSee('1 x Rp 25.000');
        $response->assertSee('item-subtotal', false);
        $response->assertSee('Rp 25.000');
    }

    /**
     * Test 6: Invoice number panjang membungkus dengan baik tanpa memecah layout.
     */
    public function test_invoice_number_panjang(): void
    {
        // Max 50 characters for invoices.invoice_number column
        $longInvoiceNumber = 'INV-20261122-SR-LONG-INVOICE-NO-9999999999999999';
        $invoice = Invoice::create([
            'invoice_number' => $longInvoiceNumber,
            'transaction_id' => null,
            'invoice_type' => Invoice::TYPE_FNB_STANDALONE,
            'subtotal' => 10000,
            'discount' => 0,
            'final_total' => 10000,
            'amount_paid' => 10000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->kasirA->id,
            'paid_at' => now(),
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_type' => InvoiceItem::ITEM_TYPE_FNB_ITEM,
            'name' => 'Snack Ringan',
            'qty' => 1,
            'unit_price_beli' => 5000,
            'unit_price_jual' => 10000,
            'subtotal' => 10000,
        ]);

        $response = $this->actingAs($this->kasirA)->get(route('fnb.invoice.show', $invoice->id));

        $response->assertStatus(200);
        $response->assertSee($longInvoiceNumber);
        $response->assertSee('word-break: break-all', false);
    }

    /**
     * Test 7: Diskon, total, bayar, dan kembalian.
     */
    public function test_diskon_total_bayar_dan_kembalian(): void
    {
        $invoice = Invoice::create([
            'invoice_number' => 'INV-20261122-DISC',
            'transaction_id' => null,
            'invoice_type' => Invoice::TYPE_FNB_STANDALONE,
            'subtotal' => 100000,
            'discount' => 15000,
            'final_total' => 85000,
            'amount_paid' => 100000,
            'change_amount' => 15000,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->kasirA->id,
            'paid_at' => now(),
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_type' => InvoiceItem::ITEM_TYPE_FNB_ITEM,
            'name' => 'Paket Makanan Rombongan',
            'qty' => 1,
            'unit_price_beli' => 60000,
            'unit_price_jual' => 100000,
            'subtotal' => 100000,
        ]);

        $response = $this->actingAs($this->kasirA)->get(route('fnb.invoice.show', $invoice->id));

        $response->assertStatus(200);
        $response->assertSee('Rp 100.000'); // Subtotal
        $response->assertSee('Diskon');
        $response->assertSee('- Rp 15.000'); // Diskon
        $response->assertSee('TOTAL');
        $response->assertSee('Rp 85.000'); // Grand Total
        $response->assertSee('Rp 100.000'); // Bayar
        $response->assertSee('Rp 15.000'); // Kembali
    }

    /**
     * Test 8: Item FnB yang tergabung dengan sesi billing.
     */
    public function test_item_fnb_yang_tergabung_dengan_sesi_billing(): void
    {
        $transaction = $this->createSampleTransaction([
            'nama' => 'Pelanggan Billing Plus FnB',
            'total' => 38000, // 30.000 rental + 8.000 fnb
            'tipe_transaksi' => 'postpaid',
            'jam_main' => '02:00:00',
            'created_at' => now()->subHours(2),
            'paid_at' => now(),
        ]);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-20261122-COMBINED',
            'transaction_id' => $transaction->id_transaksi,
            'invoice_type' => Invoice::TYPE_GAME_AND_FNB,
            'subtotal' => 38000,
            'discount' => 0,
            'final_total' => 38000,
            'amount_paid' => 50000,
            'change_amount' => 12000,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->kasirA->id,
            'paid_at' => now(),
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_type' => InvoiceItem::ITEM_TYPE_GAME_RENTAL,
            'name' => 'Rental PS5-VIP-01 (Lost Time)',
            'qty' => 1,
            'unit_price_beli' => 0,
            'unit_price_jual' => 30000,
            'subtotal' => 30000,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_type' => InvoiceItem::ITEM_TYPE_FNB_ITEM,
            'fnb_id' => $this->fnbIndomie->id,
            'name' => 'Indomie Goreng',
            'qty' => 1,
            'unit_price_beli' => 4000,
            'unit_price_jual' => 8000,
            'subtotal' => 8000,
        ]);

        $response = $this->actingAs($this->kasirA)->get(route('transaction.print', $transaction->id_transaksi));

        $response->assertStatus(200);
        $response->assertSee('PS5-VIP-01');
        $response->assertSee('02:00:00 (Lost Time)');
        $response->assertSee('Rp 30.000');
        $response->assertSee('Indomie Goreng');
        $response->assertSee('1 x Rp 8.000');
        $response->assertSee('Rp 8.000');
        $response->assertSee('Rp 38.000'); // total
    }

    /**
     * Test 9: Cetak ulang invoice yang sudah dibayar oleh kasir lain (historical preservation).
     */
    public function test_cetak_ulang_invoice_yang_sudah_dibayar(): void
    {
        $paidTime = Carbon::parse('2026-11-20 09:15:00');

        $transaction = $this->createSampleTransaction([
            'nama' => 'Pelanggan Kemarin',
            'total' => 30000,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => '2 Jam',
            'created_at' => $paidTime,
            'paid_at' => $paidTime,
        ]);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-20261120-SETTLED',
            'transaction_id' => $transaction->id_transaksi,
            'invoice_type' => Invoice::TYPE_GAME_ONLY,
            'subtotal' => 30000,
            'discount' => 0,
            'final_total' => 30000,
            'amount_paid' => 100000,
            'change_amount' => 70000,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->kasirA->id, // Budi Kasir Pagi settled it
            'paid_at' => $paidTime,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_type' => InvoiceItem::ITEM_TYPE_GAME_RENTAL,
            'name' => 'Rental PS5-VIP-01 (2 Jam)',
            'qty' => 1,
            'unit_price_beli' => 0,
            'unit_price_jual' => 30000,
            'subtotal' => 30000,
        ]);

        // Kasir B (Siti Kasir Malam) logs in and reprints without any session payment data
        $response = $this->actingAs($this->kasirB)->get(route('transaction.print', $transaction->id_transaksi));

        $response->assertStatus(200);
        // Must show Budi Kasir Pagi (who settled it), NOT Siti Kasir Malam
        $response->assertSee('Budi Kasir Pagi');
        $response->assertDontSee('Siti Kasir Malam');
        // Must show exact settlement timestamp
        $response->assertSee('20/11/2026 09:15');
        // Must show exact historical amount paid and change
        $response->assertSee('Rp 100.000');
        $response->assertSee('Rp 70.000');
        $response->assertSee('INV-20261120-SETTLED');
    }

    /**
     * Test 10: Tidak ada data pembayaran yang berubah akibat printing.
     */
    public function test_tidak_ada_data_pembayaran_yang_berubah_akibat_printing(): void
    {
        $paidTime = Carbon::parse('2026-11-21 16:00:00');

        $transaction = $this->createSampleTransaction([
            'nama' => 'Pelanggan Read Only',
            'total' => 15000,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => '1 Jam',
            'created_at' => $paidTime,
            'paid_at' => $paidTime,
        ]);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-20261121-READONLY',
            'transaction_id' => $transaction->id_transaksi,
            'invoice_type' => Invoice::TYPE_GAME_ONLY,
            'subtotal' => 15000,
            'discount' => 0,
            'final_total' => 15000,
            'amount_paid' => 20000,
            'change_amount' => 5000,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->kasirA->id,
            'paid_at' => $paidTime,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_type' => InvoiceItem::ITEM_TYPE_GAME_RENTAL,
            'name' => 'Rental PS5-VIP-01 (1 Jam)',
            'qty' => 1,
            'unit_price_beli' => 0,
            'unit_price_jual' => 15000,
            'subtotal' => 15000,
        ]);

        $txBefore = $transaction->fresh()->toArray();
        $invBefore = $invoice->fresh()->toArray();

        // Print multiple times as different users
        $this->actingAs($this->kasirA)->get(route('transaction.print', $transaction->id_transaksi));
        $this->actingAs($this->kasirB)->get(route('transaction.print', $transaction->id_transaksi));
        $this->actingAs($this->kasirA)->get(route('fnb.invoice.show', $invoice->id));

        $txAfter = $transaction->fresh()->toArray();
        $invAfter = $invoice->fresh()->toArray();

        $this->assertEquals($txBefore['total'], $txAfter['total']);
        $this->assertEquals($txBefore['payment_status'], $txAfter['payment_status']);
        $this->assertEquals($txBefore['paid_at'], $txAfter['paid_at']);
        $this->assertEquals($invBefore['amount_paid'], $invAfter['amount_paid']);
        $this->assertEquals($invBefore['change_amount'], $invAfter['change_amount']);
        $this->assertEquals($invBefore['final_total'], $invAfter['final_total']);
        $this->assertEquals($invBefore['cashier_user_id'], $invAfter['cashier_user_id']);
        $this->assertEquals($invBefore['paid_at'], $invAfter['paid_at']);
    }

    /**
     * Test 11: Pengujian render HTML dengan simulasi ukuran thermal 80mm dan tidak ada body { width: auto }.
     */
    public function test_pengujian_render_html_thermal_80mm(): void
    {
        $invoice = Invoice::create([
            'invoice_number' => 'INV-20261122-RENDER',
            'transaction_id' => null,
            'invoice_type' => Invoice::TYPE_FNB_STANDALONE,
            'subtotal' => 8000,
            'discount' => 0,
            'final_total' => 8000,
            'amount_paid' => 10000,
            'change_amount' => 2000,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->kasirA->id,
            'paid_at' => now(),
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_type' => InvoiceItem::ITEM_TYPE_FNB_ITEM,
            'name' => 'Indomie Goreng',
            'qty' => 1,
            'unit_price_beli' => 4000,
            'unit_price_jual' => 8000,
            'subtotal' => 8000,
        ]);

        $response = $this->actingAs($this->kasirA)->get(route('fnb.invoice.show', $invoice->id));
        $html = $response->getContent();

        // Must define 80mm paper roll with auto continuous height and zero margin
        $this->assertStringContainsString('size: 80mm auto;', $html);
        $this->assertStringContainsString('margin: 0mm;', $html);

        // Body must NOT be width: auto in print media
        $this->assertStringNotContainsString('body { width: auto', $html);
        $this->assertStringNotContainsString('body{width:auto', $html);

        // Standard 72mm printable content width
        $this->assertStringContainsString('width: 72mm', $html);

        // Buttons must have .no-print
        $this->assertStringContainsString('class="no-print"', $html);
    }
}
