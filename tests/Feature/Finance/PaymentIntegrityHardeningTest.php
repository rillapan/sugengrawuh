<?php

namespace Tests\Feature\Finance;

use App\Models\Device;
use App\Models\Fnb;
use App\Models\FnbOrder;
use App\Models\FnbOrderItem;
use App\Models\Invoice;
use App\Models\Playstation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Billing\LostTimeBillingService;
use App\Services\Billing\PaketBillingService;
use App\Services\Fnb\FnbStockService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentIntegrityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;
    protected Device $device;
    protected Playstation $playstation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier = User::create([
            'name' => 'Kasir 1',
            'email' => 'kasir1@test.com',
            'password' => bcrypt('password123'),
            'role' => 'kasir',
            'is_fnb_staff' => false,
        ]);

        $this->playstation = Playstation::create([
            'nama' => 'PS5 Pro 01',
            'harga' => 15000,
        ]);

        $this->device = Device::create([
            'nama' => 'Station 01',
            'status' => 'Tersedia',
            'playstation_id' => $this->playstation->id,
        ]);
    }

    /**
     * AC-06: PaketBillingService menolak settlement jika tidak ada kasir valid (tanpa fallback tebakan)
     */
    public function test_paket_settlement_rejects_missing_cashier(): void
    {
        $tx = Transaction::create([
            'nama' => 'Budi',
            'nama_pelanggan' => 'Budi',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->cashier->id,
            'tipe_transaksi' => 'prepaid',
            'status' => 'Berjalan',
            'status_transaksi' => 'draft',
            'billing_status' => Transaction::BILLING_RUNNING,
            'payment_status' => 'unpaid',
            'waktu_mulai' => '10:00',
            'waktu_selesai' => '11:00',
            'harga' => 15000,
            'jam_main' => 1,
            'total' => 15000,
            'paket_payable_total' => 15000,
        ]);

        $service = app(PaketBillingService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Kasir penerima pembayaran wajib diisi dan valid.');

        $service->activatePaidPaket($tx, [
            'payment_method' => 'tunai',
            'amount_paid' => 15000,
            'cashier_user_id' => 999999, // user tidak ada
        ]);
    }

    /**
     * AC-06: LostTimeBillingService checkoutLostTime menolak kasir tidak valid
     */
    public function test_lost_time_checkout_rejects_missing_cashier(): void
    {
        $tx = Transaction::create([
            'nama' => 'Andi',
            'nama_pelanggan' => 'Andi',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->cashier->id,
            'tipe_transaksi' => 'postpaid',
            'status' => 'Selesai',
            'status_transaksi' => 'selesai',
            'billing_status' => Transaction::BILLING_STOPPED_EARLY,
            'payment_status' => 'unpaid',
            'waktu_mulai' => '10:00',
            'waktu_selesai' => '11:00',
            'harga' => 15000,
            'total' => 15000,
            'started_at' => Carbon::now()->subHour(),
            'stopped_at' => Carbon::now(),
        ]);

        $service = app(LostTimeBillingService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Kasir penerima pembayaran wajib diisi dan valid.');

        $service->checkoutLostTime($tx->id_transaksi, [
            'payment_method' => 'tunai',
            'amount_paid' => 15000,
            'cashier_user_id' => 999999,
        ]);
    }

    /**
     * AC-06: LostTimeBillingService settleFnbOrder menolak kasir tidak valid
     */
    public function test_settle_fnb_order_rejects_missing_cashier(): void
    {
        $fnb = Fnb::create([
            'nama' => 'Teh Botol',
            'harga_jual' => 5000,
            'harga_beli' => 3000,
            'stok' => 10,
            'status' => 'Tersedia',
        ]);

        $order = FnbOrder::create([
            'order_number' => 'ORD-TEST-01',
            'customer_name' => 'Budi',
            'status' => FnbOrder::STATUS_ACCEPTED,
            'payment_status' => 'unpaid',
            'subtotal' => 5000,
            'total' => 5000,
            'created_by_user_id' => $this->cashier->id,
        ]);

        FnbOrderItem::create([
            'fnb_order_id' => $order->id,
            'fnb_id' => $fnb->id,
            'qty' => 1,
            'unit_price_beli' => 3000,
            'unit_price_jual' => 5000,
            'subtotal' => 5000,
            'payment_status' => 'unpaid',
        ]);

        $service = app(LostTimeBillingService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Kasir penerima pembayaran wajib diisi dan valid.');

        $service->settleFnbOrder($order->id, [
            'payment_method' => 'tunai',
            'amount_paid' => 5000,
            'cashier_user_id' => 999999,
        ]);
    }

    /**
     * AC-07: FnbStockService menolak pembatalan order yang sudah dibayar (paid)
     */
    public function test_fnb_stock_service_rejects_cancellation_of_paid_order(): void
    {
        $fnb = Fnb::create([
            'nama' => 'Kopi Tubruk',
            'harga_jual' => 4000,
            'harga_beli' => 2000,
            'stok' => 10,
            'status' => 'Tersedia',
        ]);

        $order = FnbOrder::create([
            'order_number' => 'ORD-PAID-01',
            'customer_name' => 'Budi',
            'source' => FnbOrder::SOURCE_CASHIER,
            'status' => FnbOrder::STATUS_ACCEPTED,
            'payment_status' => 'paid',
            'subtotal' => 4000,
            'total' => 4000,
            'created_by_user_id' => $this->cashier->id,
        ]);

        FnbOrderItem::create([
            'fnb_order_id' => $order->id,
            'fnb_id' => $fnb->id,
            'qty' => 1,
            'unit_price_beli' => 2000,
            'unit_price_jual' => 4000,
            'subtotal' => 4000,
            'payment_status' => 'paid',
        ]);

        $stockService = app(FnbStockService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Pesanan yang sudah dibayar tidak dapat dibatalkan.');

        $stockService->cancelAndRestoreStock($order->id, $this->cashier->id, 'Pelanggan minta batal');
    }
}
