<?php

namespace Tests\Feature\Finance;

use App\Models\Invoice;
use App\Models\User;
use App\Models\WorkShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserChannelLockTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $billingCashier;
    protected User $fnbCashier;
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
            'name' => 'Super Admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('password123'),
            'role' => 'admin',
        ]);

        $this->billingCashier = User::create([
            'name' => 'Kasir Billing 1',
            'email' => 'billing@test.com',
            'password' => bcrypt('password123'),
            'role' => 'kasir',
            'is_fnb_staff' => false,
            'work_shift_id' => $this->shift->id,
            'shift' => 'Pagi',
        ]);

        $this->fnbCashier = User::create([
            'name' => 'Kasir FnB 1',
            'email' => 'fnb@test.com',
            'password' => bcrypt('password123'),
            'role' => 'kasir',
            'is_fnb_staff' => true,
            'work_shift_id' => $this->shift->id,
            'shift' => 'Pagi',
        ]);
    }

    /**
     * AC-16: Admin mencoba mengubah Kasir Billing menjadi Kasir F&B -> Ditolak (422 / validation error)
     */
    public function test_admin_cannot_change_billing_cashier_to_fnb_cashier(): void
    {
        $response = $this->actingAs($this->admin)->put("/users/{$this->billingCashier->id}", [
            'name' => 'Kasir Billing 1 Mutasi',
            'email' => 'billing@test.com',
            'role' => 'kasir',
            'is_fnb_staff' => '1',
            'work_shift_id' => $this->shift->id,
            'shift' => 'Pagi',
        ]);

        $response->assertSessionHasErrors('is_fnb_staff');
        $this->billingCashier->refresh();
        $this->assertFalse((bool) $this->billingCashier->is_fnb_staff);
    }

    /**
     * AC-17: Admin mencoba mengubah Kasir F&B menjadi Kasir Billing -> Ditolak (422 / validation error)
     */
    public function test_admin_cannot_change_fnb_cashier_to_billing_cashier(): void
    {
        $response = $this->actingAs($this->admin)->put("/users/{$this->fnbCashier->id}", [
            'name' => 'Kasir FnB 1 Mutasi',
            'email' => 'fnb@test.com',
            'role' => 'kasir',
            'is_fnb_staff' => '0',
            'work_shift_id' => $this->shift->id,
            'shift' => 'Pagi',
        ]);

        $response->assertSessionHasErrors('is_fnb_staff');
        $this->fnbCashier->refresh();
        $this->assertTrue((bool) $this->fnbCashier->is_fnb_staff);
    }

    /**
     * AC-18: Admin mengubah nama/password kasir -> Berhasil diperbarui tanpa mengubah kanal
     */
    public function test_admin_can_update_name_and_password_without_changing_channel(): void
    {
        $response = $this->actingAs($this->admin)->put("/users/{$this->billingCashier->id}", [
            'name' => 'Kasir Billing Updated Name',
            'email' => 'billing_new@test.com',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
            'role' => 'kasir',
            'is_fnb_staff' => '0',
            'work_shift_id' => $this->shift->id,
            'shift' => 'Pagi',
        ]);

        $response->assertRedirect('/users');
        $this->billingCashier->refresh();
        $this->assertEquals('Kasir Billing Updated Name', $this->billingCashier->name);
        $this->assertEquals('billing_new@test.com', $this->billingCashier->email);
        $this->assertFalse((bool) $this->billingCashier->is_fnb_staff);
    }

    /**
     * AC-21: Percobaan mengubah kanal kasir via API langsung (JSON request) ditolak backend
     */
    public function test_direct_api_request_to_alter_channel_is_rejected(): void
    {
        $response = $this->actingAs($this->admin)->putJson("/users/{$this->fnbCashier->id}", [
            'name' => 'Hacker Update',
            'email' => 'fnb@test.com',
            'role' => 'kasir',
            'is_fnb_staff' => false,
            'work_shift_id' => $this->shift->id,
            'shift' => 'Pagi',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('is_fnb_staff');
        $this->fnbCashier->refresh();
        $this->assertTrue((bool) $this->fnbCashier->is_fnb_staff);
    }

    /**
     * AC-21: Guard: Kanal kasir tidak dapat dipindahkan meskipun request menyertakan role
     */
    public function test_cashier_channel_cannot_be_changed_via_role_payload(): void
    {
        $response = $this->actingAs($this->admin)->putJson("/users/{$this->billingCashier->id}", [
            'name' => 'Bypass Attempt',
            'email' => 'billing@test.com',
            'role' => 'kasir',
            'is_fnb_staff' => true,
            'work_shift_id' => $this->shift->id,
            'shift' => 'Pagi',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('is_fnb_staff');
        $this->billingCashier->refresh();
        $this->assertFalse((bool) $this->billingCashier->is_fnb_staff);
    }

    /**
     * AC-20: Akun kasir yang memiliki riwayat transaksi/invoice tidak dapat dihapus
     */
    public function test_cashier_with_invoices_cannot_be_deleted(): void
    {
        Invoice::create([
            'invoice_number' => 'INV-TEST-001',
            'invoice_type' => Invoice::TYPE_FNB_STANDALONE,
            'subtotal' => 10000,
            'discount' => 0,
            'final_total' => 10000,
            'amount_paid' => 10000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->billingCashier->id,
            'paid_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->delete("/users/{$this->billingCashier->id}");

        $response->assertRedirect('/users');
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('users', ['id' => $this->billingCashier->id]);
    }
}
