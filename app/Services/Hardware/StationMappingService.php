<?php

namespace App\Services\Hardware;

use App\Exceptions\StationMappingException;
use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\Transaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StationMappingService
{
    /**
     * Register a new physical hardware device in the inventory registry.
     *
     * @param array $data
     * @return PhysicalDevice
     * @throws StationMappingException|InvalidArgumentException
     */
    public function registerPhysicalDevice(array $data): PhysicalDevice
    {
        $category = $data['category'] ?? null;
        $brand = $data['brand'] ?? null;
        $macAddress = $data['mac_address'] ?? null;
        $ipAddress = $data['ip_address'] ?? null;

        $isConsole = ($category === PhysicalDevice::CATEGORY_GAME_CONSOLE);
        if (!$category || !$brand || (!$macAddress && !$isConsole) || !$ipAddress) {
            throw StationMappingException::invalidData("Data hardware tidak lengkap. Field name, category, brand, mac_address, dan ip_address wajib diisi.");
        }

        // Validate brand & category pairing
        PhysicalDevice::validateBrandCategory($category, $brand);

        // Validate IP address RFC 1918
        PhysicalDevice::validateIpAddress($ipAddress);

        $normalizedMac = null;
        if (!empty($macAddress)) {
            // Normalize MAC
            $normalizedMac = PhysicalDevice::normalizeMacAddress($macAddress);

            // Check duplicate MAC
            if (PhysicalDevice::where('mac_address', $normalizedMac)->exists()) {
                throw StationMappingException::duplicateMac($normalizedMac);
            }
        }

        // Check IP conflict against other active devices
        $ipConflict = PhysicalDevice::where('ip_address', $ipAddress)
            ->where('is_active', true)
            ->first();
        if ($ipConflict) {
            throw StationMappingException::ipConflict($ipAddress, $ipConflict->name);
        }

        $defaultCapabilities = match (true) {
            $category === PhysicalDevice::CATEGORY_SMART_RELAY && $brand === PhysicalDevice::BRAND_SHELLY => [
                'SWITCH_ON', 'SWITCH_OFF', 'GET_STATUS'
            ],
            $category === PhysicalDevice::CATEGORY_SMART_TV && $brand === PhysicalDevice::BRAND_SAMSUNG => [
                'GET_STATUS', 'POWER_ON', 'STANDBY', 'MUTE', 'UNMUTE', 'VOLUME_UP', 'VOLUME_DOWN'
            ],
            $category === PhysicalDevice::CATEGORY_GAME_CONSOLE => [
                'SHOW_NOTIFICATION'
            ],
            default => null,
        };

        try {
            return PhysicalDevice::create([
                'name' => $data['name'],
                'category' => $category,
                'brand' => $brand,
                'model' => $data['model'] ?? null,
                'mac_address' => $normalizedMac,
                'ip_address' => $ipAddress,
                'device_identifier' => $data['device_identifier'] ?? null,
                'device_token_hash' => $data['device_token_hash'] ?? null,
                'firmware_version' => $data['firmware_version'] ?? null,
                'homebrew_type' => $data['homebrew_type'] ?? null,
                'homebrew_version' => $data['homebrew_version'] ?? null,
                'driver_type' => $data['driver_type'] ?? null,
                'transport_config' => $data['transport_config'] ?? null,
                'connection_status' => PhysicalDevice::CONNECTION_UNVERIFIED,
                'pairing_status' => PhysicalDevice::PAIRING_UNVERIFIED,
                'pairing_payload' => $data['pairing_payload'] ?? null,
                'verified_capabilities' => $data['verified_capabilities'] ?? $defaultCapabilities,
                'last_seen_at' => null,
                'is_active' => $data['is_active'] ?? true,
            ]);
        } catch (QueryException $e) {
            $this->handleQueryException($e);
            throw $e;
        }
    }

    /**
     * Update an existing physical hardware device.
     *
     * @param int $id
     * @param array $data
     * @return PhysicalDevice
     * @throws StationMappingException
     */
    public function updatePhysicalDevice(int $id, array $data): PhysicalDevice
    {
        $device = PhysicalDevice::findOrFail($id);

        $category = $data['category'] ?? $device->category;
        $brand = $data['brand'] ?? $device->brand;
        PhysicalDevice::validateBrandCategory($category, $brand);

        if (isset($data['ip_address'])) {
            PhysicalDevice::validateIpAddress($data['ip_address']);
            $conflict = PhysicalDevice::where('ip_address', $data['ip_address'])
                ->where('is_active', true)
                ->where('id', '!=', $id)
                ->first();
            if ($conflict) {
                throw StationMappingException::ipConflict($data['ip_address'], $conflict->name);
            }
        }

        if (array_key_exists('mac_address', $data)) {
            if (!empty($data['mac_address'])) {
                $normalizedMac = PhysicalDevice::normalizeMacAddress($data['mac_address']);
                $duplicate = PhysicalDevice::where('mac_address', $normalizedMac)
                    ->where('id', '!=', $id)
                    ->exists();
                if ($duplicate) {
                    throw StationMappingException::duplicateMac($normalizedMac);
                }
                $data['mac_address'] = $normalizedMac;
            } else {
                $data['mac_address'] = null;
            }
        }

        // Prevent deactivating if device has active mapping
        if (isset($data['is_active']) && !$data['is_active'] && $device->is_active) {
            $hasActive = StationDeviceMapping::where('physical_device_id', $id)
                ->where('mapping_status', StationDeviceMapping::STATUS_ACTIVE)
                ->exists();
            if ($hasActive) {
                throw StationMappingException::cannotDeactivateMappedHardware($device->name);
            }
        }

        try {
            $device->update($data);
            return $device->fresh();
        } catch (QueryException $e) {
            $this->handleQueryException($e);
            throw $e;
        }
    }

    /**
     * Deactivate a physical hardware device safely.
     *
     * @param int $id
     * @return PhysicalDevice
     * @throws StationMappingException
     */
    public function deactivateHardware(int $id): PhysicalDevice
    {
        return DB::transaction(function () use ($id) {
            $device = PhysicalDevice::where('id', $id)->lockForUpdate()->firstOrFail();

            $hasActive = StationDeviceMapping::where('physical_device_id', $id)
                ->where('mapping_status', StationDeviceMapping::STATUS_ACTIVE)
                ->exists();

            if ($hasActive) {
                throw StationMappingException::cannotDeactivateMappedHardware($device->name);
            }

            $device->update(['is_active' => false]);
            return $device;
        });
    }

    /**
     * Attach a hardware device to a station atomically.
     *
     * @param int $stationId
     * @param int $physicalDeviceId
     * @param string $mappingRole
     * @param int|null $userId
     * @param string|null $attachNotes
     * @return StationDeviceMapping
     * @throws StationMappingException
     */
    public function attachHardware(
        int $stationId,
        int $physicalDeviceId,
        string $mappingRole,
        ?int $userId = null,
        ?string $attachNotes = null
    ): StationDeviceMapping {
        return DB::transaction(function () use ($stationId, $physicalDeviceId, $mappingRole, $userId, $attachNotes) {
            // 1. Consistent locking order: Lock station first
            $station = Device::where('id', $stationId)->lockForUpdate()->firstOrFail();

            // 2. Validate station availability (no active session / not Digunakan)
            $this->validateStationAvailability($station);

            // 3. Lock physical device second
            $device = PhysicalDevice::where('id', $physicalDeviceId)->lockForUpdate()->firstOrFail();

            if (!$device->is_active) {
                throw StationMappingException::invalidData("Hardware '{$device->name}' berstatus tidak aktif. Tidak dapat dipetakan ke stasiun.");
            }

            // 4. Validate compatibility (PS -> SMART_TV/DISPLAY_TV, Billiard -> SMART_RELAY/LIGHT_RELAY)
            $this->validateCompatibility($station, $device, $mappingRole);

            // 5. Invariant: Single active mapping per hardware (App check)
            $activeDeviceMapping = StationDeviceMapping::where('physical_device_id', $device->id)
                ->where('mapping_status', StationDeviceMapping::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($activeDeviceMapping) {
                $targetStationName = $activeDeviceMapping->station ? $activeDeviceMapping->station->nama : null;
                throw StationMappingException::hardwareAlreadyMapped($device->name, $targetStationName);
            }

            // 6. Invariant: Single active mapping per station role (App check)
            $activeStationRoleMapping = StationDeviceMapping::where('station_id', $stationId)
                ->where('mapping_role', $mappingRole)
                ->where('mapping_status', StationDeviceMapping::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($activeStationRoleMapping) {
                throw StationMappingException::activeRoleAlreadyMapped($station->nama, $mappingRole);
            }

            // 7. Insert new active mapping
            try {
                return StationDeviceMapping::create([
                    'station_id' => $stationId,
                    'physical_device_id' => $device->id,
                    'mapping_role' => $mappingRole,
                    'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
                    'attached_by_user_id' => $userId,
                    'attached_at' => now(),
                    'attach_notes' => $attachNotes,
                ]);
            } catch (QueryException $e) {
                $this->handleQueryException($e);
                throw $e;
            }
        });
    }

    /**
     * Replace a hardware device on a station atomically (soft-detaches old, attaches new).
     *
     * @param int $stationId
     * @param int $newPhysicalDeviceId
     * @param string $mappingRole
     * @param int|null $userId
     * @param string|null $attachNotes
     * @param string|null $detachNotes
     * @return StationDeviceMapping
     * @throws StationMappingException
     */
    public function replaceHardware(
        int $stationId,
        int $newPhysicalDeviceId,
        string $mappingRole,
        ?int $userId = null,
        ?string $attachNotes = null,
        ?string $detachNotes = null
    ): StationDeviceMapping {
        return DB::transaction(function () use (
            $stationId,
            $newPhysicalDeviceId,
            $mappingRole,
            $userId,
            $attachNotes,
            $detachNotes
        ) {
            // 1. Consistent locking order: Lock station first
            $station = Device::where('id', $stationId)->lockForUpdate()->firstOrFail();

            // 2. Validate station availability
            $this->validateStationAvailability($station);

            // 3. Lock new physical device second
            $newDevice = PhysicalDevice::where('id', $newPhysicalDeviceId)->lockForUpdate()->firstOrFail();

            if (!$newDevice->is_active) {
                throw StationMappingException::invalidData("Hardware baru '{$newDevice->name}' berstatus tidak aktif. Tidak dapat dipetakan ke stasiun.");
            }

            // 4. Validate compatibility
            $this->validateCompatibility($station, $newDevice, $mappingRole);

            // 5. Invariant: New device must not already be mapped to another station
            $existingNewDeviceMapping = StationDeviceMapping::where('physical_device_id', $newDevice->id)
                ->where('mapping_status', StationDeviceMapping::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($existingNewDeviceMapping) {
                if ($existingNewDeviceMapping->station_id === $stationId && $existingNewDeviceMapping->mapping_role === $mappingRole) {
                    throw StationMappingException::invalidData("Hardware '{$newDevice->name}' sudah terpasang aktif pada stasiun ini untuk peran '{$mappingRole}'.");
                }
                $targetStationName = $existingNewDeviceMapping->station ? $existingNewDeviceMapping->station->nama : null;
                throw StationMappingException::hardwareAlreadyMapped($newDevice->name, $targetStationName);
            }

            // 6. Find and soft-detach current active mapping on this station for this role (if any)
            $currentMapping = StationDeviceMapping::where('station_id', $stationId)
                ->where('mapping_role', $mappingRole)
                ->where('mapping_status', StationDeviceMapping::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($currentMapping) {
                $currentMapping->update([
                    'mapping_status' => StationDeviceMapping::STATUS_INACTIVE,
                    'detached_at' => now(),
                    'detached_by_user_id' => $userId,
                    'detach_notes' => $detachNotes ?? "Digantikan oleh hardware {$newDevice->name}",
                ]);
            }

            // 7. Create new active mapping
            try {
                return StationDeviceMapping::create([
                    'station_id' => $stationId,
                    'physical_device_id' => $newDevice->id,
                    'mapping_role' => $mappingRole,
                    'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
                    'attached_by_user_id' => $userId,
                    'attached_at' => now(),
                    'attach_notes' => $attachNotes,
                ]);
            } catch (QueryException $e) {
                $this->handleQueryException($e);
                throw $e;
            }
        });
    }

    /**
     * Soft detach an active hardware mapping from a station.
     *
     * @param int $stationId
     * @param string $mappingRole
     * @param int|null $userId
     * @param string|null $detachNotes
     * @return StationDeviceMapping
     * @throws StationMappingException
     */
    public function softDetach(
        int $stationId,
        string $mappingRole,
        ?int $userId = null,
        ?string $detachNotes = null
    ): StationDeviceMapping {
        return DB::transaction(function () use ($stationId, $mappingRole, $userId, $detachNotes) {
            // 1. Lock station
            $station = Device::where('id', $stationId)->lockForUpdate()->firstOrFail();

            // 2. Validate station availability
            $this->validateStationAvailability($station);

            // 3. Find active mapping for this role
            $mapping = StationDeviceMapping::where('station_id', $stationId)
                ->where('mapping_role', $mappingRole)
                ->where('mapping_status', StationDeviceMapping::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if (!$mapping) {
                throw StationMappingException::notFound("Tidak ada pemetaan hardware aktif untuk stasiun '{$station->nama}' dengan peran '{$mappingRole}'.");
            }

            // 4. Soft detach: transition to INACTIVE and record audit trail
            $mapping->update([
                'mapping_status' => StationDeviceMapping::STATUS_INACTIVE,
                'detached_at' => now(),
                'detached_by_user_id' => $userId,
                'detach_notes' => $detachNotes ?? 'Pelepasan hardware dari stasiun',
            ]);

            return $mapping->fresh();
        });
    }

    /**
     * Get active mapping(s) for a station.
     *
     * @param int $stationId
     * @param string|null $mappingRole
     * @return StationDeviceMapping|Collection|null
     */
    public function getActiveMapping(int $stationId, ?string $mappingRole = null)
    {
        $query = StationDeviceMapping::with('physicalDevice')
            ->where('station_id', $stationId)
            ->where('mapping_status', StationDeviceMapping::STATUS_ACTIVE);

        if ($mappingRole !== null) {
            return $query->where('mapping_role', $mappingRole)->first();
        }

        return $query->get();
    }

    /**
     * Get complete historical mapping audit log for a station.
     *
     * @param int $stationId
     * @return Collection
     */
    public function getMappingHistory(int $stationId): Collection
    {
        return StationDeviceMapping::with(['physicalDevice', 'attachedBy', 'detachedBy'])
            ->where('station_id', $stationId)
            ->orderByDesc('attached_at')
            ->get();
    }

    /**
     * Validate compatibility between Station category and Hardware device category & role.
     *
     * @param Device $station
     * @param PhysicalDevice $device
     * @param string $mappingRole
     * @return void
     * @throws StationMappingException
     */
    public function validateCompatibility(Device $station, PhysicalDevice $device, string $mappingRole): void
    {
        $stationCategory = $station->getStationCategory();

        if ($stationCategory === null) {
            throw StationMappingException::unknownStationCategory($station->nama);
        }

        if ($stationCategory === Playstation::CATEGORY_PLAYSTATION) {
            if ($device->category === PhysicalDevice::CATEGORY_SMART_TV) {
                if ($mappingRole !== StationDeviceMapping::ROLE_DISPLAY_TV) {
                    throw StationMappingException::incompatibleHardware(
                        "Peran mapping untuk Smart TV pada stasiun PlayStation harus DISPLAY_TV, bukan {$mappingRole}."
                    );
                }
            } elseif ($device->category === PhysicalDevice::CATEGORY_GAME_CONSOLE) {
                if ($mappingRole !== StationDeviceMapping::ROLE_GAME_CONSOLE) {
                    throw StationMappingException::incompatibleHardware(
                        "Peran mapping untuk Game Console pada stasiun PlayStation harus GAME_CONSOLE, bukan {$mappingRole}."
                    );
                }
            } else {
                throw StationMappingException::incompatibleHardware(
                    "Stasiun PlayStation '{$station->nama}' hanya dapat dipetakan dengan Smart TV (SMART_TV) atau Game Console (GAME_CONSOLE). Hardware '{$device->name}' adalah {$device->category}."
                );
            }
        } elseif ($stationCategory === Playstation::CATEGORY_BILLIARD) {
            if ($device->category !== PhysicalDevice::CATEGORY_SMART_RELAY) {
                throw StationMappingException::incompatibleHardware(
                    "Stasiun Biliar '{$station->nama}' hanya dapat dipetakan dengan Smart Relay (SMART_RELAY). Hardware '{$device->name}' adalah {$device->category}."
                );
            }
            if ($mappingRole !== StationDeviceMapping::ROLE_LIGHT_RELAY) {
                throw StationMappingException::incompatibleHardware(
                    "Peran mapping untuk Smart Relay pada stasiun Biliar harus LIGHT_RELAY, bukan {$mappingRole}."
                );
            }
        } else {
            throw StationMappingException::unknownStationCategory($station->nama);
        }
    }

    /**
     * Validate station availability for mutations:
     * Must not be in 'Digunakan' state and must not have active RUNNING billing transaction.
     *
     * @param Device $station
     * @return void
     * @throws StationMappingException
     */
    public function validateStationAvailability(Device $station): void
    {
        if ($station->status === 'Digunakan') {
            throw StationMappingException::stationInUse($station->nama);
        }

        $hasRunningBilling = Transaction::where('device_id', $station->id)
            ->where('billing_status', Transaction::BILLING_RUNNING)
            ->exists();

        if ($hasRunningBilling) {
            throw StationMappingException::activeBillingRunning($station->nama);
        }
    }

    /**
     * Translate database QueryException (e.g. MySQL unique constraint violation 1062)
     * into domain-level StationMappingException with HTTP 409 status code.
     *
     * @param QueryException $e
     * @return void
     * @throws StationMappingException
     */
    protected function handleQueryException(QueryException $e): void
    {
        $message = $e->getMessage();

        if (str_contains($message, '1062 Duplicate entry') || str_contains($message, 'Integrity constraint violation')) {
            if (str_contains($message, 'uq_active_physical_device')) {
                throw new StationMappingException("Konflik: Hardware fisik sudah memiliki pemetaan aktif pada stasiun lain.", 409, $e);
            }
            if (str_contains($message, 'uq_active_station_role')) {
                throw new StationMappingException("Konflik: Stasiun sudah memiliki hardware aktif untuk peran ini.", 409, $e);
            }
            if (str_contains($message, 'uq_physical_devices_mac')) {
                throw new StationMappingException("Konflik: MAC Address sudah terdaftar dalam sistem.", 409, $e);
            }
            throw new StationMappingException("Konflik integritas basis data: data duplikat ditemukan.", 409, $e);
        }
    }
}
