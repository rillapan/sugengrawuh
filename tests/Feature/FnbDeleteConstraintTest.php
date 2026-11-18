<?php

namespace Tests\Feature;

use App\Models\Fnb;
use App\Models\FnbOrder;
use App\Models\FnbOrderItem;
use App\Models\Location;
use App\Models\PriceGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FnbDeleteConstraintTest extends TestCase
{
    use RefreshDatabase;

    protected function createAdmin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
        ]);
    }

    protected function createFnbWithImage(string $name = 'Es Teh'): Fnb
    {
        Storage::fake('public');
        $priceGroup = PriceGroup::create([
            'nama' => 'Minuman',
            'harga' => 5000,
        ]);

        $imagePath = UploadedFile::fake()->image('fnb.jpg')->store('fnb', 'public');

        return Fnb::create([
            'nama' => $name,
            'harga_beli' => 2000,
            'harga_jual' => 5000,
            'stok' => 10,
            'price_group_id' => $priceGroup->id,
            'image' => $imagePath,
        ]);
    }

    public function test_cannot_delete_fnb_referenced_by_fnb_order_items_and_preserves_image()
    {
        $admin = $this->createAdmin();
        $fnb = $this->createFnbWithImage('Kopi Susu');

        $location = Location::create([
            'code' => 'M1',
            'name' => 'Meja 1',
            'location_type' => 'ROOM',
            'is_active' => true,
        ]);

        $order = FnbOrder::create([
            'order_number' => 'ORD-TEST-001',
            'source' => 'CASHIER',
            'location_id' => $location->id,
            'customer_name' => 'Test Customer',
            'status' => 'PENDING',
            'payment_status' => 'unpaid',
            'created_by_user_id' => $admin->id,
        ]);

        FnbOrderItem::create([
            'fnb_order_id' => $order->id,
            'fnb_id' => $fnb->id,
            'qty' => 1,
            'unit_price_beli' => 2000,
            'unit_price_jual' => 5000,
            'subtotal' => 5000,
            'payment_status' => 'unpaid',
        ]);

        $response = $this->actingAs($admin)->delete(route('fnb.destroy', $fnb->id));

        $response->assertRedirect('/fnb');
        $response->assertSessionHas('gagal');
        $this->assertDatabaseHas('fnbs', ['id' => $fnb->id]);
        Storage::disk('public')->assertExists($fnb->image);
    }

    public function test_can_delete_unused_fnb_and_removes_image()
    {
        $admin = $this->createAdmin();
        $fnb = $this->createFnbWithImage('Snack Baru');
        $imagePath = $fnb->image;

        $response = $this->actingAs($admin)->delete(route('fnb.destroy', $fnb->id));

        $response->assertRedirect('/fnb');
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('fnbs', ['id' => $fnb->id]);
        Storage::disk('public')->assertMissing($imagePath);
    }
}
