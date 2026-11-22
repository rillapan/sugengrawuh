<?php

namespace Tests\Feature\Finance;

use App\Models\Device;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Playstation;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WorkShift;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardMultiCashierTest extends TestCase
{
    use RefreshDatabase;

    protected User $billingCashier;
    protected User $fnbCashier;
    protected User $admin;
    protected WorkShift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shift = WorkShift::create([
            'nama_shift' => 'Pagi',
            'jam_mulai' => '08:00:00',
            'jam_selesai' => '16:00:00',
        ]);

        $this->admin = User::create([
            'name' => 'Owner Sugeng',
            'email' => 'owner@test.com',
            'password' => bcrypt('password123'),
            'role' => 'owner',
        ]);

        $this->billingCashier = User::create([
            'name' => 'Kasir Billing',
            'email' => 'billing@test.com',
            'password' => bcrypt('password123'),
            'role' => 'kasir',
            'is_fnb_staff' => false,
            'work_shift_id' => $this->shift->id,
            'shift' => 'Pagi',
        ]);

        $this->fnbCashier = User::create([
            'name' => 'Kasir FnB',
            'email' => 'fnb@test.com',
            'password' => bcrypt('password123'),
            'role' => 'kasir',
            'is_fnb_staff' => true,
            'work_shift_id' => $this->shift->id,
            'shift' => 'Pagi',
        ]);
    }

    /**
     * AC-13: Kasir F&B dapat mengakses /home (status 200, bukan redirect ke /fnb/queue)
     */
    public function test_fnb_cashier_can_access_dashboard_home(): void
    {
        $response = $this->actingAs($this->fnbCashier)->get('/home');

        $response->assertStatus(200);
        $response->assertViewIs('home');
        $response->assertSee('Penerimaan Kasir F&amp;B', false);
        $response->assertSee('Buka Antrean Order');
        $response->assertSee('Buat Pesanan Baru');
    }

    /**
     * AC-01 & AC-02: Dashboard Kasir F&B hanya menampilkan penerimaan uang oleh Kasir F&B
     */
    public function test_fnb_cashier_dashboard_scopes_revenue_to_fnb_cashier(): void
    {
        $now = Carbon::now();

        // Invoice diterima Kasir Billing: 50.000
        Invoice::create([
            'invoice_number' => 'INV-B01',
            'subtotal' => 50000,
            'discount' => 0,
            'final_total' => 50000,
            'amount_paid' => 50000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->billingCashier->id,
            'paid_at' => $now,
        ]);

        // Invoice diterima Kasir F&B: 25.000
        Invoice::create([
            'invoice_number' => 'INV-F01',
            'subtotal' => 25000,
            'discount' => 0,
            'final_total' => 25000,
            'amount_paid' => 25000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->fnbCashier->id,
            'paid_at' => $now,
        ]);

        // Kasir F&B accesses /home
        $responseFnb = $this->actingAs($this->fnbCashier)->get('/home');
        $responseFnb->assertStatus(200);
        $responseFnb->assertViewHas('todayRevenue', 25000.0);
        $responseFnb->assertViewHas('invoiceCount', 1);

        // Kasir Billing accesses /home
        $responseBilling = $this->actingAs($this->billingCashier)->get('/home');
        $responseBilling->assertStatus(200);
        $responseBilling->assertViewHas('todayRevenue', 50000.0);
        $responseBilling->assertViewHas('invoiceCount', 1);
    }

    /**
     * AC-13: Kasir F&B dapat mengakses endpoint chart /hourly-revenue-data tanpa 403
     * dan datanya tersaring hanya untuk penerimaan Kasir F&B
     */
    public function test_fnb_cashier_can_access_hourly_revenue_data_scoped(): void
    {
        $now = Carbon::now()->setHour(11);

        // Billing receipt at 11:00 = 60.000
        Invoice::create([
            'invoice_number' => 'INV-B11',
            'subtotal' => 60000,
            'discount' => 0,
            'final_total' => 60000,
            'amount_paid' => 60000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->billingCashier->id,
            'paid_at' => $now,
        ]);

        // F&B receipt at 11:00 = 35.000
        Invoice::create([
            'invoice_number' => 'INV-F11',
            'subtotal' => 35000,
            'discount' => 0,
            'final_total' => 35000,
            'amount_paid' => 35000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->fnbCashier->id,
            'paid_at' => $now,
        ]);

        // Kasir F&B chart request
        $response = $this->actingAs($this->fnbCashier)->getJson('/hourly-revenue-data');
        $response->assertStatus(200);
        $data = $response->json();

        $this->assertEquals(35000, array_sum($data['datasets'][0]['data']));
        $this->assertStringContainsString('Penerimaan Kasir F&B', $data['datasets'][0]['label']);

        // Kasir Billing chart request
        $responseBilling = $this->actingAs($this->billingCashier)->getJson('/hourly-revenue-data');
        $responseBilling->assertStatus(200);
        $dataBilling = $responseBilling->json();

        $this->assertEquals(60000, array_sum($dataBilling['datasets'][0]['data']));
        $this->assertStringContainsString('Penerimaan Kasir Billing', $dataBilling['datasets'][0]['label']);

        // Admin chart request: sees consolidated 95.000
        $responseAdmin = $this->actingAs($this->admin)->getJson('/hourly-revenue-data');
        $responseAdmin->assertStatus(200);
        $dataAdmin = $responseAdmin->json();

        $this->assertEquals(95000, $dataAdmin['datasets'][0]['data'][11]);
    }

    /**
     * Admin/Owner melihat dashboard konsolidasi (Billing + F&B + Admin)
     */
    public function test_admin_sees_consolidated_dashboard_and_categories(): void
    {
        $now = Carbon::now();

        Invoice::create([
            'invoice_number' => 'INV-B-TOT',
            'subtotal' => 100000,
            'discount' => 0,
            'final_total' => 100000,
            'amount_paid' => 100000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->billingCashier->id,
            'paid_at' => $now,
        ]);

        Invoice::create([
            'invoice_number' => 'INV-F-TOT',
            'subtotal' => 40000,
            'discount' => 0,
            'final_total' => 40000,
            'amount_paid' => 40000,
            'change_amount' => 0,
            'payment_method' => 'e-wallet',
            'cashier_user_id' => $this->fnbCashier->id,
            'paid_at' => $now,
        ]);

        $response = $this->actingAs($this->admin)->get('/home');
        $response->assertStatus(200);

        // Grand total konsolidasi = 140.000
        $response->assertViewHas('todayRevenue', 140000.0);
        $response->assertSee('Penerimaan Konsolidasi Usaha Hari Ini');
    }
}
