<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Playstation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function createAdmin(): User
    {
        return User::factory()->create([
            'email' => 'admin_device_test@gmail.com',
            'role' => 'admin',
        ]);
    }

    public function test_cannot_delete_device_with_unpaid_transactions()
    {
        $admin = $this->createAdmin();
        $ps = Playstation::create(['nama' => 'Rental PS', 'harga' => 10000]);

        $device = Device::create([
            'nama' => 'Rental PS5-01',
            'status' => 'Tersedia',
            'playstation_id' => $ps->id,
        ]);

        Transaction::create([
            'status' => 'Aktif',
            'device_id' => $device->id,
            'harga' => '10000',
            'jam_main' => '1',
            'waktu_mulai' => '10:00:00',
            'waktu_Selesai' => '11:00:00',
            'total' => '10000',
            'status_transaksi' => 'belum_selesai',
            'payment_status' => 'unpaid',
        ]);

        $response = $this->actingAs($admin)->delete(route('device.destroy', $device->id));

        $response->assertRedirect('device');
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('devices', ['id' => $device->id]);
    }

    public function test_can_delete_device_without_active_or_unpaid_transactions()
    {
        $admin = $this->createAdmin();
        $ps = Playstation::create(['nama' => 'Rental PS', 'harga' => 10000]);

        $device = Device::create([
            'nama' => 'Rental PS5-02',
            'status' => 'Tersedia',
            'playstation_id' => $ps->id,
        ]);

        $response = $this->actingAs($admin)->delete(route('device.destroy', $device->id));

        $response->assertRedirect('device');
        $response->assertSessionHas('success', 'Data perangkat berhasil dihapus.');
        $this->assertDatabaseMissing('devices', ['id' => $device->id]);
    }
}
