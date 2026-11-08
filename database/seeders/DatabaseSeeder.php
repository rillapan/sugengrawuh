<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Database\Seeders\UserSeeder;
use Database\Seeders\BookingSeeder;
use Database\Seeders\DevicePlaystationSeeder;
use Database\Seeders\WorkShiftSeeder;
use Database\Seeders\PlaystationSeeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        $this->call([
            WorkShiftSeeder::class,
            PlaystationSeeder::class,
            UserSeeder::class,
            BookingSeeder::class,
            DevicePlaystationSeeder::class,
        ]);

        // Buat user admin
        \App\Models\User::updateOrCreate([
            'email' => 'admin@gmail.com',
        ], [
            'name' => 'Admin',
            'password' => bcrypt('password'), // password
            'role' => 'admin',
        ]);

        // Buat user owner
        \App\Models\User::updateOrCreate([
            'email' => 'owner@gmail.com',
        ], [
            'name' => 'Owner',
            'password' => bcrypt('password'),
            'role' => 'owner',
        ]);

       
    }
}
