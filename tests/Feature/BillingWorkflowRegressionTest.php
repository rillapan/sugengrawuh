<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Device;
use App\Models\Playstation;
use App\Models\Transaction;
use App\Models\CustomPackage;
use App\Models\Fnb;
use App\Models\TransactionFnb;
use App\Models\StockMutation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

class BillingWorkflowRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;
    protected $kasir;
    protected $owner;
    protected $playstation;
    protected $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin@billing.test'
        ]);

        $this->kasir = User::factory()->create([
            'role' => 'kasir',
            'email' => 'kasir@billing.test'
        ]);

        $this->owner = User::factory()->create([
            'role' => 'owner',
            'email' => 'owner@billing.test'
        ]);

        $this->playstation = Playstation::create([
            'nama' => 'PS5 Deluxe',
            'harga' => 10000
        ]);

        $this->device = Device::create([
            'nama' => 'Station-01',
            'playstation_id' => $this->playstation->id,
            'status' => 'Tersedia'
        ]);

        $this->device->playstations()->attach($this->playstation->id);
    }

    /** @test */
    public function test_can_create_prepaid_billing_and_occupies_device()
    {
        $response = $this->actingAs($this->kasir)
            ->post(route('transaction.store'), [
                'nama' => 'Pelanggan Prepaid',
                'device_id' => $this->device->id,
                'playstation_id' => $this->playstation->id,
                'tipe_transaksi' => 'prepaid',
                'jam_main' => 2,
                'harga' => 10000,
                'total' => 20000
            ]);

        $response->assertRedirect('transaction');

        $this->assertDatabaseHas('transactions', [
            'nama' => 'Pelanggan Prepaid',
            'tipe_transaksi' => 'prepaid',
            'device_id' => $this->device->id,
            'total' => '20000'
        ]);

        $this->device->refresh();
        $this->assertEquals('Digunakan', $this->device->status);
    }

    /** @test */
    public function test_can_create_postpaid_lost_time_billing()
    {
        $response = $this->actingAs($this->kasir)
            ->post(route('transaction.store'), [
                'nama' => 'Pelanggan Lost Time',
                'device_id' => $this->device->id,
                'playstation_id' => $this->playstation->id,
                'tipe_transaksi' => 'postpaid'
            ]);

        $response->assertRedirect('transaction');

        $this->assertDatabaseHas('transactions', [
            'nama' => 'Pelanggan Lost Time',
            'tipe_transaksi' => 'postpaid',
            'device_id' => $this->device->id,
            'payment_status' => 'unpaid'
        ]);

        $this->device->refresh();
        $this->assertEquals('Digunakan', $this->device->status);
    }

    /** @test */
    public function test_can_create_custom_package_billing()
    {
        $package = CustomPackage::create([
            'nama_paket' => 'Paket Hemat 2 Jam',
            'harga_total' => 25000,
            'is_active' => true
        ]);
        $package->playstations()->attach($this->playstation->id, ['lama_main' => 120]);

        $response = $this->actingAs($this->kasir)
            ->post(route('transaction.store'), [
                'nama' => 'Pelanggan Custom Paket',
                'device_id' => $this->device->id,
                'custom_package_id' => $package->id,
                'harga' => 25000,
                'total' => 25000,
                'tipe_transaksi' => 'custom_package'
            ]);

        $response->assertRedirect('transaction');

        $this->assertDatabaseHas('transactions', [
            'nama' => 'Pelanggan Custom Paket',
            'tipe_transaksi' => 'custom_package',
            'custom_package_id' => $package->id,
            'total' => 25000
        ]);
    }

    /** @test */
    public function test_price_calculation_and_discount_application()
    {
        $transaction = Transaction::create([
            'nama' => 'Member Diskon',
            'device_id' => $this->device->id,
            'status' => 'user',
            'harga' => 10000,
            'jam_main' => 2,
            'waktu_mulai' => '10:00',
            'waktu_Selesai' => '12:00',
            'total' => '20000',
            'tipe_transaksi' => 'prepaid',
            'payment_status' => 'unpaid'
        ]);
        $this->device->update(['status' => 'Digunakan']);

        // Bayar dengan diskon 10% (final = 18000), nominal bayar 20000 (kembalian 2000)
        $response = $this->actingAs($this->kasir)
            ->post(route('transaction.processPayment', $transaction->id_transaksi), [
                'payment_method' => 'tunai',
                'discount' => 10,
                'amount_paid' => 20000
            ]);

        $response->assertRedirect(route('transaction.print', ['id' => $transaction->id_transaksi, 'action' => 'payment']));

        $transaction->refresh();
        $this->assertEquals('paid', $transaction->payment_status);
        $this->assertEquals(10, (int)$transaction->diskon);
        $this->assertNotNull($transaction->paid_at);

        $this->device->refresh();
        $this->assertEquals('Tersedia', $this->device->status);
    }

    /** @test */
    public function test_prevent_double_payment_on_paid_transaction()
    {
        $transaction = Transaction::create([
            'nama' => 'Pelanggan Sudah Lunas',
            'device_id' => $this->device->id,
            'status' => 'user',
            'harga' => 10000,
            'jam_main' => 1,
            'waktu_mulai' => '10:00',
            'waktu_Selesai' => '11:00',
            'total' => '10000',
            'tipe_transaksi' => 'prepaid',
            'payment_status' => 'paid',
            'payment_method' => 'tunai'
        ]);

        $response = $this->actingAs($this->kasir)
            ->post(route('transaction.processPayment', $transaction->id_transaksi), [
                'payment_method' => 'tunai',
                'amount_paid' => 10000
            ]);

        $response->assertRedirect(route('transaction.print', $transaction->id_transaksi));
        $response->assertSessionHas('gagal', 'Transaksi ini sudah lunas.');
    }

    /** @test */
    public function test_prevent_deletion_of_paid_transaction()
    {
        $transaction = Transaction::create([
            'nama' => 'Audit Keuangan Permanen',
            'device_id' => $this->device->id,
            'status' => 'user',
            'harga' => 10000,
            'jam_main' => 1,
            'waktu_mulai' => '10:00',
            'waktu_Selesai' => '11:00',
            'total' => '10000',
            'tipe_transaksi' => 'prepaid',
            'payment_status' => 'paid'
        ]);

        $response = $this->actingAs($this->admin)
            ->delete(route('transaction.destroy', $transaction->id_transaksi));

        $response->assertRedirect('transaction');
        $response->assertSessionHas('gagal', 'Transaksi yang sudah lunas tidak dapat dihapus demi integritas audit keuangan.');

        $this->assertDatabaseHas('transactions', [
            'id_transaksi' => $transaction->id_transaksi
        ]);
    }

    /** @test */
    public function test_can_delete_unpaid_transaction_and_releases_device()
    {
        $this->device->update(['status' => 'Digunakan']);

        $transaction = Transaction::create([
            'nama' => 'Transaksi Batal Unpaid',
            'device_id' => $this->device->id,
            'status' => 'user',
            'harga' => 10000,
            'jam_main' => 1,
            'waktu_mulai' => '10:00',
            'waktu_Selesai' => '11:00',
            'total' => '10000',
            'tipe_transaksi' => 'prepaid',
            'payment_status' => 'unpaid'
        ]);

        $response = $this->actingAs($this->admin)
            ->delete(route('transaction.destroy', $transaction->id_transaksi));

        $response->assertRedirect('transaction');
        $response->assertSessionHas('success', 'Data transaksi berhasil dihapus.');

        $this->assertDatabaseMissing('transactions', [
            'id_transaksi' => $transaction->id_transaksi
        ]);

        $this->device->refresh();
        $this->assertEquals('Tersedia', $this->device->status);
    }

    /** @test */
    public function test_owner_is_forbidden_from_creating_transaction()
    {
        $response = $this->actingAs($this->owner)
            ->post(route('transaction.store'), [
                'nama' => 'Owner Mencoba Buat',
                'device_id' => $this->device->id,
                'tipe_transaksi' => 'prepaid'
            ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function test_kasir_is_forbidden_from_deleting_transaction()
    {
        $transaction = Transaction::create([
            'nama' => 'Kasir Coba Hapus',
            'device_id' => $this->device->id,
            'status' => 'user',
            'harga' => 10000,
            'jam_main' => 1,
            'waktu_mulai' => '10:00',
            'waktu_Selesai' => '11:00',
            'total' => '10000',
            'tipe_transaksi' => 'prepaid',
            'payment_status' => 'unpaid'
        ]);

        $response = $this->actingAs($this->kasir)
            ->delete(route('transaction.destroy', $transaction->id_transaksi));

        $response->assertStatus(403);
        $this->assertDatabaseHas('transactions', [
            'id_transaksi' => $transaction->id_transaksi
        ]);
    }

    /** @test */
    public function test_concurrent_payment_requests_handled_safely()
    {
        $transaction = Transaction::create([
            'nama' => 'Concurrent Payment Test',
            'device_id' => $this->device->id,
            'status' => 'user',
            'harga' => 10000,
            'jam_main' => 1,
            'waktu_mulai' => '10:00',
            'waktu_Selesai' => '11:00',
            'total' => '10000',
            'tipe_transaksi' => 'prepaid',
            'payment_status' => 'unpaid'
        ]);

        // Request 1: First payment attempt succeeds
        $response1 = $this->actingAs($this->kasir)
            ->post(route('transaction.processPayment', $transaction->id_transaksi), [
                'payment_method' => 'tunai',
                'amount_paid' => 10000
            ]);

        $response1->assertRedirect(route('transaction.print', ['id' => $transaction->id_transaksi, 'action' => 'payment']));

        // Request 2: Simultaneous/second payment attempt immediately detects already paid
        $response2 = $this->actingAs($this->kasir)
            ->post(route('transaction.processPayment', $transaction->id_transaksi), [
                'payment_method' => 'tunai',
                'amount_paid' => 10000
            ]);

        $response2->assertRedirect(route('transaction.print', $transaction->id_transaksi));
        $response2->assertSessionHas('gagal', 'Transaksi ini sudah lunas.');

        // Ensure transaction is still properly marked paid only once
        $transaction->refresh();
        $this->assertEquals('paid', $transaction->payment_status);
    }

    /** @test */
    public function test_deleting_unpaid_transaction_with_fnb_restores_stock_and_mutation()
    {
        $fnb = Fnb::create([
            'nama' => 'Snack Chitato',
            'harga_beli' => 6000,
            'harga_jual' => 10000,
            'stok' => 15
        ]);

        $transaction = Transaction::create([
            'nama' => 'Transaksi Unpaid Ada FnB',
            'device_id' => $this->device->id,
            'status' => 'user',
            'harga' => 10000,
            'jam_main' => 1,
            'waktu_mulai' => '10:00',
            'waktu_Selesai' => '11:00',
            'total' => '30000',
            'tipe_transaksi' => 'prepaid',
            'payment_status' => 'unpaid'
        ]);
        $this->device->update(['status' => 'Digunakan']);

        // Attach FnB to transaction and simulate stock deduction of 2 items (15 -> 13)
        TransactionFnb::create([
            'transaction_id' => $transaction->id_transaksi,
            'fnb_id' => $fnb->id,
            'qty' => 2,
            'harga_jual' => 10000
        ]);
        $fnb->decrement('stok', 2);
        $this->assertEquals(13, $fnb->fresh()->stok);

        // Admin deletes the unpaid transaction
        $response = $this->actingAs($this->admin)
            ->delete(route('transaction.destroy', $transaction->id_transaksi));

        $response->assertRedirect('transaction');
        $response->assertSessionHas('success', 'Data transaksi berhasil dihapus.');

        // 1. Transaction must be deleted
        $this->assertDatabaseMissing('transactions', [
            'id_transaksi' => $transaction->id_transaksi
        ]);

        // 2. FnB stock must be restored back to 15
        $this->assertEquals(15, $fnb->fresh()->stok);

        // 3. StockMutation 'in' record must be recorded
        $this->assertDatabaseHas('stock_mutations', [
            'fnb_id' => $fnb->id,
            'type' => 'in',
            'qty' => 2,
            'note' => 'Penghapusan Transaksi Unpaid #' . $transaction->id_transaksi
        ]);

        // 4. Device status must be restored to 'Tersedia'
        $this->device->refresh();
        $this->assertEquals('Tersedia', $this->device->status);
    }

    /** @test */
    public function test_kasir_can_access_all_legitimate_cashier_routes()
    {
        $this->actingAs($this->kasir);

        // Kasir can access transaction index & create
        $this->get('/transaction')->assertStatus(200);
        $this->get('/transaction/create')->assertStatus(200);

        // Kasir can access device index
        $this->get('/device')->assertStatus(200);

        // Kasir can access expense index & create
        $this->get('/expense')->assertStatus(200);
        $this->get('/expense/create')->assertStatus(200);
    }

    /** @test */
    public function test_admin_can_access_all_management_routes()
    {
        $this->actingAs($this->admin);

        // Admin can access admin routes
        $this->get('/users')->assertStatus(200);
        $this->get('/kategori-permainan')->assertStatus(200);
        $this->get('/playstation')->assertRedirect('/kategori-permainan');
        $this->get('/fnb')->assertStatus(200);
        $this->get('/price-group')->assertStatus(200);
        $this->get('/stock')->assertStatus(200);
        $this->get('/expense-category')->assertStatus(200);
        $this->get('/custom-package')->assertStatus(200);
        $this->get('/settings')->assertStatus(200);
        $this->get('/work_shifts')->assertStatus(200);
        $this->get('/report')->assertStatus(200);
    }
}
