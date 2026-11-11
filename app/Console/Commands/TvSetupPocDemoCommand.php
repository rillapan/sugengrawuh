<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Hardware\TvAlertService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TvSetupPocDemoCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tv:setup-poc-demo {--minutes=9 : Remaining minutes for PS 01 prepaid session}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set up ready-to-test PlayStation stations (PS 01 & PS 02) with Samsung 43FE60 TV mapping and active billing session for 2-tab browser & Tizen PoC demo';

    /**
     * Execute the console command.
     */
    public function handle(TvAlertService $tvAlertService): int
    {
        if (!app()->environment(['local', 'testing'])) {
            $this->error('Perintah tv:setup-poc-demo hanya diizinkan pada environment local atau testing.');
            return Command::FAILURE;
        }

        $activeDb = DB::connection()->getDatabaseName();
        $allowedDbs = config('hardware.allowed_databases', ['kasir_ps_test']);
        if (!in_array($activeDb, $allowedDbs, true)) {
            $this->error("Database aktif ({$activeDb}) bukan database simulasi yang diizinkan (" . implode(', ', $allowedDbs) . "). Eksekusi dibatalkan demi keamanan data operasional.");
            return Command::FAILURE;
        }

        $remainingMinutes = max(1, (int) $this->option('minutes'));

        $lanHostIp = $tvAlertService->getLanHostIp();
        $lanBaseUrl = $tvAlertService->getLanBaseUrl();

        $this->info("=== Menyiapkan Samsung 43FE60 TV Alert & Control PoC Demo (DB: {$activeDb}, LAN Host: {$lanHostIp}) ===");

        // 1. Ensure Admin user exists
        $admin = User::where('role', 'admin')->first();
        if (!$admin) {
            $admin = User::create([
                'name' => 'Admin Sugeng Rawuh',
                'username' => 'admin_poc',
                'email' => 'admin@sugengrawuh.test',
                'password' => bcrypt('password123'),
                'role' => 'admin',
            ]);
            $this->info("Admin dibuat: {$admin->email} (password: password123)");
        } else {
            $this->info("Admin tersedia: {$admin->email}");
        }

        // 2. Ensure PlayStation category exists
        $psCategory = Playstation::firstOrCreate(
            ['nama' => 'PS 4'],
            ['harga' => 15000]
        );

        // 3. Ensure PS 01 (id=1) & PS 02 (id=2) stations and Samsung 43FE60 TV mappings exist
        $stationsConfig = [
            [
                'preferred_id' => 1,
                'station_name' => 'PS 01',
                'tv_name' => 'Samsung 43FE60 - PS 01',
                'mac' => 'AA:BB:CC:43:FE:01',
                'ip' => '192.168.1.101',
                'start_active_session' => true,
            ],
            [
                'preferred_id' => 2,
                'station_name' => 'PS 02',
                'tv_name' => 'Samsung 43FE60 - PS 02',
                'mac' => 'AA:BB:CC:43:FE:02',
                'ip' => '192.168.1.102',
                'start_active_session' => false,
            ],
        ];

        $tableRows = [];
        $primaryStation = null;

        foreach ($stationsConfig as $cfg) {
            $station = Device::find($cfg['preferred_id']);
            if (!$station) {
                $station = new Device([
                    'nama' => 'TEMP_' . $cfg['preferred_id'],
                    'status' => 'Tersedia',
                    'playstation_id' => $psCategory->id,
                    'session_generation' => 1,
                ]);
                $station->id = $cfg['preferred_id'];
                $station->save();
            }

            // Re-point or clean up any duplicate demo stations with same name but non-preferred ID
            $duplicateIds = Device::where('nama', $cfg['station_name'])
                ->where('id', '!=', $cfg['preferred_id'])
                ->pluck('id')
                ->all();

            if (!empty($duplicateIds)) {
                if (\Illuminate\Support\Facades\Schema::hasTable('automation_execution_logs')) {
                    DB::table('automation_execution_logs')->whereIn('station_id', $duplicateIds)->delete();
                }
                if (\Illuminate\Support\Facades\Schema::hasTable('automation_intents')) {
                    DB::table('automation_intents')->whereIn('station_id', $duplicateIds)->delete();
                }
                if (\Illuminate\Support\Facades\Schema::hasTable('tv_control_events')) {
                    DB::table('tv_control_events')->whereIn('station_id', $duplicateIds)->delete();
                }
                StationDeviceMapping::whereIn('station_id', $duplicateIds)->delete();
                Transaction::whereIn('device_id', $duplicateIds)->delete();
                DB::table('device_playstation')->whereIn('device_id', $duplicateIds)->delete();
                Device::whereIn('id', $duplicateIds)->delete();
            }

            $station->update([
                'nama' => $cfg['station_name'],
                'playstation_id' => $psCategory->id,
            ]);

            if (!$station->playstations()->where('playstations.id', $psCategory->id)->exists()) {
                $station->playstations()->attach($psCategory->id);
            }

            $tv = PhysicalDevice::updateOrCreate(
                ['mac_address' => $cfg['mac']],
                [
                    'name' => $cfg['tv_name'],
                    'category' => PhysicalDevice::CATEGORY_SMART_TV,
                    'brand' => PhysicalDevice::BRAND_SAMSUNG,
                    'model' => '43FE60',
                    'ip_address' => $cfg['ip'],
                    'connection_status' => PhysicalDevice::CONNECTION_ONLINE,
                    'pairing_status' => PhysicalDevice::PAIRING_PAIRED,
                    'is_active' => true,
                ]
            );

            // Ensure active DISPLAY_TV mapping for this station
            $existingMapping = StationDeviceMapping::where('station_id', $station->id)
                ->where('mapping_role', StationDeviceMapping::ROLE_DISPLAY_TV)
                ->where('mapping_status', StationDeviceMapping::STATUS_ACTIVE)
                ->first();

            if (!$existingMapping) {
                StationDeviceMapping::create([
                    'station_id' => $station->id,
                    'physical_device_id' => $tv->id,
                    'mapping_role' => StationDeviceMapping::ROLE_DISPLAY_TV,
                    'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
                    'attached_by_user_id' => $admin->id,
                    'attached_at' => Carbon::now(),
                ]);
            } elseif ((int) $existingMapping->physical_device_id !== (int) $tv->id) {
                $existingMapping->update(['physical_device_id' => $tv->id]);
            }

            $activeTx = Transaction::where('device_id', $station->id)
                ->where('billing_status', Transaction::BILLING_RUNNING)
                ->latest('id_transaksi')
                ->first();

            if ($cfg['start_active_session']) {
                $now = Carbon::now();
                $expiresAt = $now->copy()->addMinutes($remainingMinutes);
                $gen = max(1, (int) ($station->session_generation ?: 1));

                $station->update([
                    'status' => 'Digunakan',
                    'session_generation' => $gen,
                ]);

                if (!$activeTx) {
                    $activeTx = Transaction::create([
                        'status' => 'user',
                        'nama' => 'Pelanggan Demo PS 01',
                        'device_id' => $station->id,
                        'playstation_id' => $psCategory->id,
                        'user_id' => $admin->id,
                        'tipe_transaksi' => 'prepaid',
                        'jam_main' => 1,
                        'waktu_mulai' => $now->format('H:i'),
                        'waktu_Selesai' => $expiresAt->format('H:i'),
                        'started_at' => $now,
                        'expires_at' => $expiresAt,
                        'harga' => $psCategory->harga,
                        'total' => $psCategory->harga,
                        'status_transaksi' => 'sukses',
                        'billing_status' => Transaction::BILLING_RUNNING,
                        'payment_status' => 'paid',
                        'session_generation' => $gen,
                    ]);
                } else {
                    $activeTx->update([
                        'started_at' => $now,
                        'expires_at' => $expiresAt,
                        'waktu_Selesai' => $expiresAt->format('H:i'),
                        'billing_status' => Transaction::BILLING_RUNNING,
                        'status_transaksi' => 'sukses',
                        'session_generation' => $gen,
                    ]);
                }

                $primaryStation = $station->fresh();
            }

            $station->refresh();
            $devToken = "dev_tv_station_{$station->id}";
            $hmacToken = $tvAlertService->generateConsumerToken($station->id, $tv->id);

            $tableRows[] = [
                $station->id,
                $station->nama,
                $station->status,
                "{$tv->name} ({$tv->model})",
                $activeTx ? "#{$activeTx->id_transaksi} ({$remainingMinutes}m left)" : '-',
                (int) ($station->session_generation ?: 1),
                $devToken,
                $hmacToken,
            ];
        }

        $this->table(
            ['Station ID', 'Station Name', 'Status', 'Mapped TV', 'Active Tx', 'Gen', 'Dev Token', 'Signed HMAC Token'],
            $tableRows
        );

        if ($primaryStation) {
            $sid = $primaryStation->id;
            $devToken = "dev_tv_station_{$sid}";
            $this->line('');
            $this->info('Direct URLs for 2-Tab Browser & Tizen Studio Testing:');
            $this->line("  1. Panel Kontrol Kasir (/device) : http://127.0.0.1:8000/device");
            $this->line("  2. Simulator Layar 16:9 (/tv-lab): http://127.0.0.1:8000/admin/tv-lab?station_id={$sid}");
            $this->line("  3. Consumer Poll API (Local)     : http://127.0.0.1:8000/api/tv-agent/events?station_id={$sid}&token={$devToken}");
            $this->line("  4. Consumer Poll API (LAN Direct): {$lanBaseUrl}/api/tv-agent/events?station_id={$sid}&token={$devToken}");
            $this->line("  5. Tizen Web App Query (Local)   : index.html?station_id={$sid}&token={$devToken}&api_url=http://127.0.0.1:8000");
            $this->line("  6. Tizen Web App Query (LAN Direct): index.html?station_id={$sid}&token={$devToken}&api_url={$lanBaseUrl}");
        }

        return Command::SUCCESS;
    }
}
