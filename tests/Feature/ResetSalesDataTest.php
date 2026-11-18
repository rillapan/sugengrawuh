<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Fnb;
use App\Models\FnbOrder;
use App\Models\FnbOrderItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Location;
use App\Models\Playstation;
use App\Models\PriceGroup;
use App\Models\StockMutation;
use App\Models\Transaction;
use App\Models\TransactionFnb;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ResetSalesDataTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Device $device;
    protected Fnb $fnb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin Utama',
            'email' => 'admin_reset@example.com',
            'password' => Hash::make('secret123'),
            'role' => 'admin',
        ]);

        $ps = Playstation::create([
            'nama' => 'PS5 VIP',
            'harga' => 20000,
        ]);

        $this->device = Device::create([
            'nama' => 'Station VIP 1',
            'status' => 'Digunakan',
            'playstation_id' => $ps->id,
        ]);

        $priceGroup = PriceGroup::create([
            'nama' => 'Minuman',
            'harga' => 5000,
        ]);

        $this->fnb = Fnb::create([
            'nama' => 'Kopi Good Day',
            'harga_beli' => 2000,
            'harga_jual' => 5000,
            'stok' => 50,
            'price_group_id' => $priceGroup->id,
        ]);
    }

    public function test_reset_fails_if_password_is_incorrect(): void
    {
        $response = $this->actingAs($this->admin)->post(route('settings.reset-transactions'), [
            'confirm_delete' => '1',
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect(route('settings.index'));
        $response->assertSessionHas('gagal', 'Password konfirmasi salah. Reset transaksi dibatalkan.');
    }

    public function test_reset_sales_data_successfully_wipes_transactions_and_reports_while_preserving_master_data(): void
    {
        $location = Location::create([
            'code' => 'VIP-1',
            'name' => 'Meja VIP 1',
            'location_type' => 'ROOM',
            'is_active' => true,
        ]);

        // 1. Buat data transaksi rental
        $tx = Transaction::create([
            'status' => 'user',
            'nama' => 'Pelanggan 1',
            'device_id' => $this->device->id,
            'playstation_id' => $this->device->playstation_id,
            'harga' => 20000,
            'jam_main' => '1',
            'waktu_mulai' => '10:00:00',
            'waktu_Selesai' => '11:00:00',
            'total' => 25000,
            'status_transaksi' => 'selesai',
            'payment_status' => 'paid',
        ]);

        // 2. Buat data pesanan fnb & transaksi fnb
        $txFnb = TransactionFnb::create([
            'transaction_id' => $tx->id_transaksi,
            'fnb_id' => $this->fnb->id,
            'qty' => 1,
            'harga_jual' => 5000,
            'harga_beli' => 2000,
        ]);

        $order = FnbOrder::create([
            'order_number' => 'ORD-RESET-01',
            'source' => 'CASHIER',
            'location_id' => $location->id,
            'transaction_id' => $tx->id_transaksi,
            'customer_name' => 'Pelanggan 1',
            'status' => 'DELIVERED',
            'payment_status' => 'paid',
            'created_by_user_id' => $this->admin->id,
        ]);

        $orderItem = FnbOrderItem::create([
            'fnb_order_id' => $order->id,
            'fnb_id' => $this->fnb->id,
            'qty' => 1,
            'unit_price_beli' => 2000,
            'unit_price_jual' => 5000,
            'subtotal' => 5000,
            'payment_status' => 'paid',
        ]);

        // 3. Buat Invoice dan InvoiceItems
        $invoice = Invoice::create([
            'invoice_number' => 'INV-RESET-01',
            'transaction_id' => $tx->id_transaksi,
            'invoice_type' => 'GAME_AND_FNB',
            'subtotal' => 25000,
            'discount' => 0,
            'final_total' => 25000,
            'amount_paid' => 30000,
            'change_amount' => 5000,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->admin->id,
            'paid_at' => now(),
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_type' => 'GAME_RENTAL',
            'name' => 'Rental PS5 VIP',
            'qty' => 1,
            'unit_price_beli' => 0,
            'unit_price_jual' => 20000,
            'subtotal' => 20000,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_type' => 'FNB_ITEM',
            'fnb_id' => $this->fnb->id,
            'fnb_order_item_id' => $orderItem->id,
            'transaction_fnb_id' => $txFnb->id,
            'name' => 'Kopi Good Day',
            'qty' => 1,
            'unit_price_beli' => 2000,
            'unit_price_jual' => 5000,
            'subtotal' => 5000,
        ]);

        // 4. Mutasi stok penjualan vs mutasi stok manual
        StockMutation::create([
            'fnb_id' => $this->fnb->id,
            'type' => 'out',
            'qty' => 1,
            'date' => now()->toDateString(),
            'note' => 'Penjualan transaksi #' . $tx->id_transaksi,
        ]);

        StockMutation::create([
            'fnb_id' => $this->fnb->id,
            'type' => 'in',
            'qty' => 10,
            'date' => now()->toDateString(),
            'note' => 'Kulakan restock gudang',
        ]);

        $this->assertEquals(1, Transaction::count());
        $this->assertEquals(1, Invoice::count());
        $this->assertEquals(2, InvoiceItem::count());
        $this->assertEquals(1, FnbOrder::count());
        $this->assertEquals(1, FnbOrderItem::count());
        $this->assertEquals(1, TransactionFnb::count());
        $this->assertEquals(2, StockMutation::count());

        // Jalankan Reset Transaksi
        $response = $this->actingAs($this->admin)->post(route('settings.reset-transactions'), [
            'confirm_delete' => '1',
            'password' => 'secret123',
        ]);

        $response->assertRedirect(route('settings.index'));
        $response->assertSessionHas('success');

        // Pastikan seluruh data transaksi & penjualan terhapus
        $this->assertEquals(0, Transaction::count(), 'Semua transactions harus terhapus.');
        $this->assertEquals(0, Invoice::count(), 'Semua invoices harus terhapus.');
        $this->assertEquals(0, InvoiceItem::count(), 'Semua invoice_items harus terhapus.');
        $this->assertEquals(0, FnbOrder::count(), 'Semua fnb_orders harus terhapus.');
        $this->assertEquals(0, FnbOrderItem::count(), 'Semua fnb_order_items harus terhapus.');
        $this->assertEquals(0, TransactionFnb::count(), 'Semua transaction_fnbs harus terhapus.');

        // Mutasi stok penjualan terhapus, tetapi mutasi stok restock manual tetap ada
        $this->assertEquals(1, StockMutation::count());
        $this->assertEquals('Kulakan restock gudang', StockMutation::first()->note);

        // Pastikan DATA MASTER TIDAK TERHAPUS
        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
        $this->assertDatabaseHas('devices', ['id' => $this->device->id]);
        $this->assertDatabaseHas('fnbs', ['id' => $this->fnb->id]);
        $this->assertDatabaseHas('locations', ['id' => $location->id]);

        // Pastikan status perangkat kembali menjadi Tersedia
        $this->assertEquals('Tersedia', $this->device->fresh()->status);
    }
}
