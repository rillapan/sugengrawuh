<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Device;
use App\Models\Playstation;

class DevicePlaystationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Guard against recreating sample devices when inventory already exists
        if (Device::count() > 0) {
            return;
        }

        // Create a sample device with multiple PlayStation types
        $device = Device::create([
            'nama' => 'Meja 1 - Billiard',
            'playstation_id' => 3, // Default to BILLIARD pagi for backward compatibility
            'status' => 'Tersedia'
        ]);

        // Attach multiple PlayStation types to this device
        $device->playstations()->attach([3, 4]); // BILLIARD pagi and BILLIARD sore

        // Create another sample device
        $device2 = Device::create([
            'nama' => 'Meja 2 - Billiard',
            'playstation_id' => 3,
            'status' => 'Tersedia'
        ]);

        $device2->playstations()->attach([3, 4]);
    }
}
