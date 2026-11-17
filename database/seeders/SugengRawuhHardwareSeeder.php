<?php

namespace Database\Seeders;

use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\Transaction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SugengRawuhHardwareSeeder extends Seeder
{
    /**
     * Source of truth inventory for 20 physical devices (4 Billiard, 16 TV).
     */
    public const INVENTORY = [
        // 4 Billiard Tables
        [
            'nama' => 'Billiard 1',
            'kategori' => 'BILLIARD',
            'ip_address' => '192.168.200.241',
            'mac_address' => 'DC:B4:D9:DD:46:AC',
            'legacy_names' => ['Meja 1 - Billiard', 'Meja 1', 'Billiard 1'],
        ],
        [
            'nama' => 'Billiard 2',
            'kategori' => 'BILLIARD',
            'ip_address' => '192.168.200.242',
            'mac_address' => 'DC:B4:D9:DD:12:E8',
            'legacy_names' => ['Meja 2 - Billiard', 'Meja 2', 'Billiard 2'],
        ],
        [
            'nama' => 'Billiard 3',
            'kategori' => 'BILLIARD',
            'ip_address' => '192.168.200.243',
            'mac_address' => 'DC:B4:D9:DD:46:70',
            'legacy_names' => ['Meja 3 - Billiard', 'Meja 3', 'Billiard 3'],
        ],
        [
            'nama' => 'Billiard 4',
            'kategori' => 'BILLIARD',
            'ip_address' => '192.168.200.244',
            'mac_address' => 'DC:B4:D9:DE:26:08',
            'legacy_names' => ['Meja 4 - Billiard', 'Meja 4', 'Billiard 4'],
        ],

        // 16 TVs
        [
            'nama' => 'TV1',
            'kategori' => 'TV',
            'ip_address' => '192.168.200.201',
            'mac_address' => '28:DE:1C:01:2C:1B',
            'legacy_names' => ['TV 1 [UJI COBA]', 'TV 1', 'TV1'],
        ],
        [
            'nama' => 'TV2',
            'kategori' => 'TV',
            'ip_address' => '192.168.200.202',
            'mac_address' => 'B8:A0:B8:79:60:2A',
            'legacy_names' => ['TV 2', 'TV2'],
        ],
        [
            'nama' => 'TV3',
            'kategori' => 'TV',
            'ip_address' => '192.168.200.203',
            'mac_address' => '98:4E:8A:24:CC:5A',
            'legacy_names' => ['TV 3', 'TV3'],
        ],
        [
            'nama' => 'TV4',
            'kategori' => 'TV',
            'ip_address' => '192.168.200.204',
            'mac_address' => '98:4E:8A:2A:62:A4',
            'legacy_names' => ['TV 4', 'TV4'],
        ],
        [
            'nama' => 'TV5',
            'kategori' => 'TV',
            'ip_address' => '192.168.200.205',
            'mac_address' => 'B8:A0:B8:75:29:FC',
            'legacy_names' => ['TV 5', 'TV5'],
        ],
        [
            'nama' => 'TV6',
            'kategori' => 'TV',
            'ip_address' => '192.168.200.206',
            'mac_address' => '98:4E:8A:2A:61:BA',
            'legacy_names' => ['TV 6', 'TV6'],
        ],
        [
            'nama' => 'TV7',
            'kategori' => 'TV',
            'ip_address' => '192.168.200.207',
            'mac_address' => 'B8:A0:B8:65:62:A0',
            'legacy_names' => ['TV 7', 'TV7'],
        ],
        [
            'nama' => 'TV8',
            'kategori' => 'TV',
            'ip_address' => '192.168.200.208',
            'mac_address' => 'B8:A0:B8:5F:75:DC',
            'legacy_names' => ['TV 8', 'TV8'],
        ],
        [
            'nama' => 'TV9',
            'kategori' => 'TV',
            'ip_address' => '192.168.200.209',
            'mac_address' => 'B8:A0:B8:65:85:90',
            'legacy_names' => ['TV 9', 'TV9'],
        ],
        [
            'nama' => 'TV10',
            'kategori' => 'TV',
            'ip_address' => '192.168.200.210',
            'mac_address' => 'B8:A0:B8:5F:63:FA',
            'legacy_names' => ['TV 10', 'TV10'],
        ],
        [
            'nama' => 'TV11',
            'kategori' => 'TV',
            'ip_address' => '192.168.200.211',
            'mac_address' => 'B8:A0:B8:5F:5F:EE',
            'legacy_names' => ['TV 11', 'TV11'],
        ],
        [
            'nama' => 'TV12',
            'kategori' => 'TV',
            'ip_address' => '192.168.200.212',
            'mac_address' => 'B8:A0:B8:29:B6:A6',
            'legacy_names' => ['TV 12', 'TV12'],
        ],
        [
            'nama' => 'TV13',
            'kategori' => 'TV',
            'ip_address' => '192.168.200.213',
            'mac_address' => '38:8C:EF:90:62:50',
            'legacy_names' => ['TV 13', 'TV13'],
        ],
        [
            'nama' => 'TV14',
            'kategori' => 'TV',
            'ip_address' => '192.168.200.214',
            'mac_address' => 'B8:A0:B8:67:52:EC',
            'legacy_names' => ['TV 14', 'TV14'],
        ],
        [
            'nama' => 'TV15',
            'kategori' => 'TV',
            'ip_address' => '192.168.200.215',
            'mac_address' => '00:45:E2:FD:B1:9F',
            'legacy_names' => ['TV 15', 'TV15'],
        ],
        [
            'nama' => 'TV16',
            'kategori' => 'TV',
            'ip_address' => '192.168.200.216',
            'mac_address' => '98:4E:8A:2A:60:8A',
            'legacy_names' => ['TV 16', 'TV16'],
        ],
    ];

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $this->validateInventoryData();

        DB::transaction(function () {
            $categories = $this->ensureDefaultPlaystationCategories();
            $seededStations = $this->seedMasterDevices($categories);
            $seededHardware = $this->seedHardwareRegistry();
            $this->seedHardwareMappings($seededStations, $seededHardware);
            $this->reconcileExtraneousData($seededStations, $seededHardware);
        });

        $this->logSummary();
    }

    /**
     * Validate consistency of the defined INVENTORY constants.
     */
    protected function validateInventoryData(): void
    {
        if (count(self::INVENTORY) !== 20) {
            throw new RuntimeException("Data inventaris harus tepat 20 perangkat, ditemukan: " . count(self::INVENTORY));
        }

        $ips = [];
        $macs = [];
        $names = [];

        foreach (self::INVENTORY as $item) {
            $name = $item['nama'];
            $ip = $item['ip_address'];
            $mac = PhysicalDevice::normalizeMacAddress($item['mac_address']);

            if (isset($names[$name])) {
                throw new RuntimeException("Duplikasi nama perangkat pada inventaris: {$name}");
            }
            if (isset($ips[$ip])) {
                throw new RuntimeException("Duplikasi IP Address pada inventaris: {$ip}");
            }
            if (isset($macs[$mac])) {
                throw new RuntimeException("Duplikasi MAC Address pada inventaris: {$mac}");
            }

            PhysicalDevice::validateIpAddress($ip);

            $names[$name] = true;
            $ips[$ip] = true;
            $macs[$mac] = true;
        }
    }

    /**
     * Ensure baseline PlayStation and Billiard service categories exist in database.
     *
     * @return array
     */
    protected function ensureDefaultPlaystationCategories(): array
    {
        // 1. Billiard Services
        $billiardPagi = Playstation::firstOrCreate(
            ['nama' => 'BILLIARD pagi'],
            ['harga' => 25000, 'image' => 'post-images/billiard.jpg']
        );

        $billiardSore = Playstation::firstOrCreate(
            ['nama' => 'BILLIARD sore'],
            ['harga' => 30000, 'image' => 'post-images/billiard.jpg']
        );

        // 2. PlayStation Services
        $ps4 = Playstation::firstOrCreate(
            ['nama' => 'PS4'],
            ['harga' => 15000, 'image' => 'post-images/ps4.jpg']
        );

        return [
            'billiard_default_id' => $billiardPagi->id,
            'billiard_ids' => array_values(array_filter([$billiardPagi->id, $billiardSore->id])),
            'ps_default_id' => $ps4->id,
            'ps_ids' => [$ps4->id],
        ];
    }

    /**
     * Seed and reconcile master software devices (`devices` table).
     *
     * @param array $categories
     * @return array<string, Device>
     */
    protected function seedMasterDevices(array $categories): array
    {
        $seededStations = [];

        foreach (self::INVENTORY as $item) {
            $isBilliard = ($item['kategori'] === 'BILLIARD');
            $defaultPsId = $isBilliard ? $categories['billiard_default_id'] : $categories['ps_default_id'];
            $pivotPsIds = $isBilliard ? $categories['billiard_ids'] : $categories['ps_ids'];

            // Match existing device by target name or known legacy names
            $device = Device::where('nama', $item['nama'])->first();

            if (!$device && !empty($item['legacy_names'])) {
                $device = Device::whereIn('nama', $item['legacy_names'])->first();
            }

            if ($device) {
                // Preserve active billing status if session is running or marked Digunakan
                $preserveOccupied = ($device->status === 'Digunakan') ||
                    Transaction::where('device_id', $device->id)
                        ->where('billing_status', Transaction::BILLING_RUNNING)
                        ->exists();

                $device->update([
                    'nama' => $item['nama'],
                    'playstation_id' => $defaultPsId,
                    'status' => $preserveOccupied ? 'Digunakan' : 'Tersedia',
                ]);
            } else {
                $device = Device::create([
                    'nama' => $item['nama'],
                    'playstation_id' => $defaultPsId,
                    'status' => 'Tersedia',
                    'session_generation' => 1,
                ]);
            }

            // Sync pivot relations for station category resolution
            $device->playstations()->sync($pivotPsIds);

            $seededStations[$item['nama']] = $device;
        }

        return $seededStations;
    }

    /**
     * Seed and reconcile hardware registry (`physical_devices` table).
     *
     * @return array<string, PhysicalDevice>
     */
    protected function seedHardwareRegistry(): array
    {
        $seededHardware = [];

        $inventoryIps = array_column(self::INVENTORY, 'ip_address');
        $inventoryMacs = array_map(
            fn($item) => PhysicalDevice::normalizeMacAddress($item['mac_address']),
            self::INVENTORY
        );

        // Pre-emptively release any IP held by old non-inventory devices to prevent unique/conflict issues
        PhysicalDevice::whereIn('ip_address', $inventoryIps)
            ->whereNotIn('mac_address', $inventoryMacs)
            ->each(function (PhysicalDevice $oldDevice) {
                Log::info("[SugengRawuhHardwareSeeder] Evicting IP {$oldDevice->ip_address} from obsolete device ID {$oldDevice->id} ({$oldDevice->name})");
                $oldDevice->update([
                    'ip_address' => '10.254.254.' . min(254, (int) $oldDevice->id),
                    'is_active' => false,
                ]);
            });

        foreach (self::INVENTORY as $item) {
            $normalizedMac = PhysicalDevice::normalizeMacAddress($item['mac_address']);
            $isBilliard = ($item['kategori'] === 'BILLIARD');

            $category = $isBilliard ? PhysicalDevice::CATEGORY_SMART_RELAY : PhysicalDevice::CATEGORY_SMART_TV;
            $brand = $isBilliard ? PhysicalDevice::BRAND_SHELLY : PhysicalDevice::BRAND_SAMSUNG;

            $defaultCapabilities = $isBilliard
                ? ['SWITCH_ON', 'SWITCH_OFF', 'GET_STATUS']
                : ['GET_STATUS', 'POWER_ON', 'STANDBY', 'MUTE', 'UNMUTE', 'VOLUME_UP', 'VOLUME_DOWN'];

            $physDev = PhysicalDevice::where('mac_address', $normalizedMac)->first();

            if ($physDev) {
                $physDev->update([
                    'name' => $item['nama'],
                    'category' => $category,
                    'brand' => $brand,
                    'ip_address' => $item['ip_address'],
                    'is_active' => true,
                    'verified_capabilities' => $physDev->verified_capabilities ?: $defaultCapabilities,
                ]);
            } else {
                $physDev = PhysicalDevice::create([
                    'name' => $item['nama'],
                    'category' => $category,
                    'brand' => $brand,
                    'model' => $isBilliard ? 'Shelly 1PM Mini Gen3' : null,
                    'mac_address' => $normalizedMac,
                    'ip_address' => $item['ip_address'],
                    'is_active' => true,
                    'connection_status' => PhysicalDevice::CONNECTION_UNVERIFIED,
                    'pairing_status' => PhysicalDevice::PAIRING_UNVERIFIED,
                    'verified_capabilities' => $defaultCapabilities,
                ]);
            }

            $seededHardware[$item['nama']] = $physDev;
        }

        return $seededHardware;
    }

    /**
     * Seed and reconcile 1:1 hardware to station mappings (`station_device_mappings` table).
     *
     * @param array<string, Device> $seededStations
     * @param array<string, PhysicalDevice> $seededHardware
     * @return void
     */
    protected function seedHardwareMappings(array $seededStations, array $seededHardware): void
    {
        foreach (self::INVENTORY as $item) {
            $station = $seededStations[$item['nama']];
            $physDev = $seededHardware[$item['nama']];
            $role = ($item['kategori'] === 'BILLIARD')
                ? StationDeviceMapping::ROLE_LIGHT_RELAY
                : StationDeviceMapping::ROLE_DISPLAY_TV;

            // 1. Deactivate any conflicting active mapping on this physical device for different stations
            StationDeviceMapping::where('physical_device_id', $physDev->id)
                ->where('station_id', '!=', $station->id)
                ->where('mapping_status', StationDeviceMapping::STATUS_ACTIVE)
                ->update([
                    'mapping_status' => StationDeviceMapping::STATUS_INACTIVE,
                    'detached_at' => now(),
                    'detach_notes' => 'Deactivated during inventory re-seeding (hardware moved)',
                ]);

            // 2. Deactivate any conflicting active mapping on this station role with different hardware
            StationDeviceMapping::where('station_id', $station->id)
                ->where('mapping_role', $role)
                ->where('physical_device_id', '!=', $physDev->id)
                ->where('mapping_status', StationDeviceMapping::STATUS_ACTIVE)
                ->update([
                    'mapping_status' => StationDeviceMapping::STATUS_INACTIVE,
                    'detached_at' => now(),
                    'detach_notes' => 'Replaced by SugengRawuhHardwareSeeder',
                ]);

            // 3. Ensure active mapping between this station and physical device
            $existingMapping = StationDeviceMapping::where('station_id', $station->id)
                ->where('physical_device_id', $physDev->id)
                ->where('mapping_role', $role)
                ->first();

            if ($existingMapping) {
                if ($existingMapping->mapping_status !== StationDeviceMapping::STATUS_ACTIVE) {
                    $existingMapping->update([
                        'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
                        'detached_at' => null,
                        'detach_notes' => null,
                    ]);
                }
            } else {
                StationDeviceMapping::create([
                    'station_id' => $station->id,
                    'physical_device_id' => $physDev->id,
                    'mapping_role' => $role,
                    'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
                    'attached_at' => now(),
                    'attach_notes' => "Inventory Seeder: {$station->nama} -> {$physDev->ip_address}",
                ]);
            }
        }
    }

    /**
     * Archive obsolete hardware or deactivate stale mappings outside the 20 inventory.
     *
     * @param array<string, Device> $seededStations
     * @param array<string, PhysicalDevice> $seededHardware
     * @return void
     */
    protected function reconcileExtraneousData(array $seededStations, array $seededHardware): void
    {
        $seededStationIds = collect($seededStations)->pluck('id')->all();
        $seededHardwareIds = collect($seededHardware)->pluck('id')->all();

        // 1. Deactivate any active mapping involving non-inventory station or hardware
        StationDeviceMapping::where('mapping_status', StationDeviceMapping::STATUS_ACTIVE)
            ->where(function ($query) use ($seededStationIds, $seededHardwareIds) {
                $query->whereNotIn('station_id', $seededStationIds)
                      ->orWhereNotIn('physical_device_id', $seededHardwareIds);
            })
            ->update([
                'mapping_status' => StationDeviceMapping::STATUS_INACTIVE,
                'detached_at' => now(),
                'detach_notes' => 'Deactivated: out of inventory in SugengRawuhHardwareSeeder',
            ]);

        // 2. Mark non-inventory physical devices as inactive (archived)
        PhysicalDevice::whereNotIn('id', $seededHardwareIds)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        // 3. Clean up non-inventory devices only if they have NO transactions or history
        $extraneousDevices = Device::whereNotIn('id', $seededStationIds)->get();
        foreach ($extraneousDevices as $extraDev) {
            $hasTransactions = Transaction::where('device_id', $extraDev->id)->exists();
            $hasMappingHistory = StationDeviceMapping::where('station_id', $extraDev->id)->exists();

            if (!$hasTransactions && !$hasMappingHistory) {
                $extraDev->playstations()->detach();
                $extraDev->delete();
            } else {
                Log::warning("[SugengRawuhHardwareSeeder] Device '{$extraDev->nama}' (ID: {$extraDev->id}) tidak dapat dihapus fisik karena memiliki relasi transaksi/histori.");
            }
        }
    }

    /**
     * Print summary of inventory status to console output if available.
     */
    protected function logSummary(): void
    {
        $totalDevices = Device::count();
        $totalActiveHardware = PhysicalDevice::where('is_active', true)->count();
        $totalActiveMappings = StationDeviceMapping::where('mapping_status', StationDeviceMapping::STATUS_ACTIVE)->count();

        $billiardStations = Device::whereHas('playstations', function ($q) {
            $q->where('nama', 'like', '%BILLIARD%');
        })->count();

        $tvStations = Device::whereHas('playstations', function ($q) {
            $q->where('nama', 'like', '%PS%');
        })->count();

        if ($this->command) {
            $this->command->info("=== SUGENG RAWUH HARDWARE SEEDER BERHASIL ===");
            $this->command->line("Total Master Devices: {$totalDevices}");
            $this->command->line("Total Hardware Aktif: {$totalActiveHardware}");
            $this->command->line("Total Mapping Aktif : {$totalActiveMappings}");
            $this->command->line("  -> Billiard Stations: {$billiardStations}");
            $this->command->line("  -> TV Stations      : {$tvStations}");
        }
    }
}
