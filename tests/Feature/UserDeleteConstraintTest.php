<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserDeleteConstraintTest extends TestCase
{
    use RefreshDatabase;

    protected function createAdmin(): User
    {
        return User::factory()->create([
            'email' => 'admin_test@gmail.com',
            'role' => 'admin',
        ]);
    }

    public function test_cannot_delete_self()
    {
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin)->delete(route('users.destroy', $admin->id));

        $response->assertRedirect(route('users.index'));
        $response->assertSessionHas('error', 'Anda tidak dapat menghapus akun Anda sendiri.');
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_cannot_delete_user_referenced_by_invoices()
    {
        $admin = $this->createAdmin();
        $kasir = User::factory()->create([
            'email' => 'kasir1@gmail.com',
            'role' => 'kasir',
        ]);

        Invoice::create([
            'invoice_number' => 'INV-TEST-001',
            'invoice_type' => 'FNB_STANDALONE',
            'cashier_user_id' => $kasir->id,
            'subtotal' => 10000,
            'discount' => 0,
            'final_total' => 10000,
            'amount_paid' => 10000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'paid_at' => now(),
        ]);

        $response = $this->actingAs($admin)->delete(route('users.destroy', $kasir->id));

        $response->assertRedirect(route('users.index'));
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('users', ['id' => $kasir->id]);
    }

    public function test_can_delete_user_without_relations()
    {
        $admin = $this->createAdmin();
        $kasir = User::factory()->create([
            'email' => 'kasir2@gmail.com',
            'role' => 'kasir',
        ]);

        $response = $this->actingAs($admin)->delete(route('users.destroy', $kasir->id));

        $response->assertRedirect(route('users.index'));
        $response->assertSessionHas('success', 'User berhasil dihapus');
        $this->assertDatabaseMissing('users', ['id' => $kasir->id]);
    }
}
