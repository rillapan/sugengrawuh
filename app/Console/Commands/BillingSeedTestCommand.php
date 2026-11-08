<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\Playstation;
use App\Models\CustomPackage;
use App\Models\Fnb;
use App\Models\User;
use App\Models\HourlyPrice;
use Illuminate\Console\Command;

class BillingSeedTestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:seed-test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed comprehensive test data and accounts (OWNER, ADMIN, KASIR) for G2 manual QA';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info("Target Database: " . config('database.connections.mysql.database'));
        $this->info("Seeding test users for Manual QA...");

        // 1. Users
        $users = [
            [
                'name' => 'Owner Sugeng Rawuh',
                'username' => 'owner_qa',
                'email' => 'owner@sugengrawuh.test',
                'password' => bcrypt('password123'),
                'role' => 'owner',
            ],
            [
                'name' => 'Admin Billing',
                'username' => 'admin_qa',
                'email' => 'admin@sugengrawuh.test',
                'password' => bcrypt('password123'),
                'role' => 'admin',
            ],
            [
                'name' => 'Kasir Shift 1',
                'username' => 'kasir_qa',
                'email' => 'kasir@sugengrawuh.test',
                'password' => bcrypt('password123'),
                'role' => 'kasir',
            ],
        ];

        foreach ($users as $userData) {
            User::updateOrCreate(
                ['email' => $userData['email']],
                $userData
            );
        }

        // 2. PlayStations
        $ps4 = Playstation::firstOrCreate(
            ['nama' => 'PlayStation 4 Slim'],
            ['harga' => 8000]
        );

        $ps5 = Playstation::firstOrCreate(
            ['nama' => 'PlayStation 5 Digital'],
            ['harga' => 12000]
        );

        // Hourly prices for PS5
        $hourlyPrices = [
            1 => 12000,
            2 => 22000,
            3 => 30000,
        ];
        foreach ($hourlyPrices as $hour => $price) {
            HourlyPrice::updateOrCreate(
                ['playstation_id' => $ps5->id, 'hour' => $hour],
                ['price' => $price]
            );
        }

        // 3. Devices / Stations
        $stations = [
            ['nama' => 'Station 01 (PS4)', 'ps' => $ps4],
            ['nama' => 'Station 02 (PS5)', 'ps' => $ps5],
            ['nama' => 'Station 03 (PS5)', 'ps' => $ps5],
        ];

        foreach ($stations as $st) {
            $device = Device::firstOrCreate(
                ['nama' => $st['nama']],
                ['status' => 'Tersedia', 'playstation_id' => $st['ps']->id]
            );
            if (!$device->playstations()->where('playstations.id', $st['ps']->id)->exists()) {
                $device->playstations()->attach($st['ps']->id);
            }
        }

        // 4. FnB Items
        $fnbs = [
            ['nama' => 'Teh Botol Sosro', 'harga_beli' => 3500, 'harga_jual' => 5000, 'stok' => 50],
            ['nama' => 'Indomie Goreng Telur', 'harga_beli' => 6000, 'harga_jual' => 10000, 'stok' => 30],
            ['nama' => 'Air Mineral 600ml', 'harga_beli' => 2000, 'harga_jual' => 4000, 'stok' => -1],
        ];

        foreach ($fnbs as $fnbData) {
            Fnb::firstOrCreate(
                ['nama' => $fnbData['nama']],
                $fnbData
            );
        }

        // 5. Custom Package
        $package = CustomPackage::firstOrCreate(
            ['nama_paket' => 'Paket Puas 3 Jam PS5'],
            [
                'harga_total' => 32000,
                'deskripsi' => 'Paket hemat main PS5 selama 3 jam',
                'is_active' => true,
            ]
        );
        if (!$package->playstations()->where('playstations.id', $ps5->id)->exists()) {
            $package->playstations()->attach($ps5->id, ['lama_main' => 180]);
        }

        $this->table(
            ['Role', 'Username', 'Email', 'Password'],
            [
                ['OWNER', 'owner_qa', 'owner@sugengrawuh.test', 'password123'],
                ['ADMIN', 'admin_qa', 'admin@sugengrawuh.test', 'password123'],
                ['KASIR', 'kasir_qa', 'kasir@sugengrawuh.test', 'password123'],
            ]
        );

        $this->info("Test data & accounts successfully created for G2 Manual QA.");
        return 0;
    }
}
