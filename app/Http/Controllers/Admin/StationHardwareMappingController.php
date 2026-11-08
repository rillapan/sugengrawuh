<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\StationMappingException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AttachStationMappingRequest;
use App\Http\Requests\DetachStationMappingRequest;
use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\StationDeviceMapping;
use App\Services\Hardware\StationMappingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StationHardwareMappingController extends Controller
{
    protected StationMappingService $service;

    public function __construct(StationMappingService $service)
    {
        $this->service = $service;
    }

    /**
     * Render the Blade UI page for Station Hardware Mapping.
     * Accessible by Admin and Owner (Read-Only).
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function mappingsView(Request $request)
    {
        $stations = Device::with(['playstations', 'activeHardwareMappings.physicalDevice'])
            ->orderBy('nama')
            ->get();

        $selectedStationId = $request->query('station_id');
        $selectedStation = null;

        if ($selectedStationId) {
            $selectedStation = $stations->firstWhere('id', (int) $selectedStationId);
        }

        if (!$selectedStation && $stations->isNotEmpty()) {
            $selectedStation = $stations->first();
        }

        $activeMappings = collect();
        $historyMappings = collect();
        $availableTvs = collect();
        $availableRelays = collect();

        if ($selectedStation) {
            $activeMappings = $this->service->getActiveMapping($selectedStation->id);
            $historyMappings = $this->service->getMappingHistory($selectedStation->id);

            // Fetch compatible unmapped hardware
            $availableTvs = PhysicalDevice::active()
                ->unmapped()
                ->where('category', PhysicalDevice::CATEGORY_SMART_TV)
                ->where('brand', PhysicalDevice::BRAND_SAMSUNG)
                ->orderBy('name')
                ->get();

            $availableRelays = PhysicalDevice::active()
                ->unmapped()
                ->where('category', PhysicalDevice::CATEGORY_SMART_RELAY)
                ->where('brand', PhysicalDevice::BRAND_SHELLY)
                ->orderBy('name')
                ->get();
        }

        return view('admin.hardware.mappings', [
            'title' => 'Station Hardware Mapping',
            'active' => 'hardware-mappings',
            'stations' => $stations,
            'selectedStation' => $selectedStation,
            'activeMappings' => $activeMappings,
            'historyMappings' => $historyMappings,
            'availableTvs' => $availableTvs,
            'availableRelays' => $availableRelays,
        ]);
    }

    /**
     * Display active mappings and history for a station.
     * Accessible by Admin, Owner, and Kasir (with filtered operational view).
     *
     * @param Request $request
     * @param int $stationId
     * @return JsonResponse
     */
    public function index(Request $request, $stationId): JsonResponse
    {
        $station = Device::find($stationId);

        if (!$station) {
            return response()->json([
                'success' => false,
                'message' => "Stasiun dengan ID {$stationId} tidak ditemukan.",
            ], 404);
        }

        $activeMappings = $this->service->getActiveMapping($stationId);
        $user = auth()->user();
        $isKasirOnly = $user && $user->isKasir() && !$user->isAdmin() && !$user->isOwner();

        if ($isKasirOnly) {
            // Operational view for Cashier
            return response()->json([
                'success' => true,
                'message' => 'Informasi operasional hardware stasiun berhasil diambil.',
                'data' => [
                    'station_id' => $station->id,
                    'station_name' => $station->nama,
                    'station_status' => $station->status,
                    'station_category' => $station->getStationCategory(),
                    'active_hardware' => $activeMappings->map(function ($m) {
                        return [
                            'mapping_id' => $m->id,
                            'role' => $m->mapping_role,
                            'device_name' => $m->physicalDevice ? $m->physicalDevice->name : null,
                            'brand' => $m->physicalDevice ? $m->physicalDevice->brand : null,
                            'device_category' => $m->physicalDevice ? $m->physicalDevice->category : null,
                            'connection_status' => $m->physicalDevice ? $m->physicalDevice->connection_status : null,
                            'is_active' => $m->physicalDevice ? (bool) $m->physicalDevice->is_active : false,
                            'attached_at' => $m->attached_at ? $m->attached_at->toIso8601String() : null,
                        ];
                    }),
                ],
            ], 200);
        }

        // Full view for Admin and Owner
        $history = $this->service->getMappingHistory($stationId);

        return response()->json([
            'success' => true,
            'message' => 'Data pemetaan hardware stasiun berhasil diambil.',
            'data' => [
                'station_id' => $station->id,
                'station_name' => $station->nama,
                'station_status' => $station->status,
                'station_category' => $station->getStationCategory(),
                'active_mappings' => $activeMappings->map(function ($m) {
                    return [
                        'mapping_id' => $m->id,
                        'role' => $m->mapping_role,
                        'device' => $m->physicalDevice ? [
                            'id' => $m->physicalDevice->id,
                            'name' => $m->physicalDevice->name,
                            'category' => $m->physicalDevice->category,
                            'brand' => $m->physicalDevice->brand,
                            'model' => $m->physicalDevice->model,
                            'mac_address' => $m->physicalDevice->mac_address,
                            'ip_address' => $m->physicalDevice->ip_address,
                            'connection_status' => $m->physicalDevice->connection_status,
                            'pairing_status' => $m->physicalDevice->pairing_status,
                            'is_active' => (bool) $m->physicalDevice->is_active,
                        ] : null,
                        'attached_by' => $m->attachedBy ? $m->attachedBy->name : null,
                        'attached_at' => $m->attached_at ? $m->attached_at->toIso8601String() : null,
                        'attach_notes' => $m->attach_notes,
                    ];
                }),
                'history' => $history->map(function ($h) {
                    return [
                        'mapping_id' => $h->id,
                        'role' => $h->mapping_role,
                        'status' => $h->mapping_status,
                        'device_name' => $h->physicalDevice ? $h->physicalDevice->name : null,
                        'device_category' => $h->physicalDevice ? $h->physicalDevice->category : null,
                        'attached_by' => $h->attachedBy ? $h->attachedBy->name : null,
                        'detached_by' => $h->detachedBy ? $h->detachedBy->name : null,
                        'attached_at' => $h->attached_at ? $h->attached_at->toIso8601String() : null,
                        'detached_at' => $h->detached_at ? $h->detached_at->toIso8601String() : null,
                        'attach_notes' => $h->attach_notes,
                        'detach_notes' => $h->detach_notes,
                    ];
                }),
            ],
        ], 200);
    }

    /**
     * Attach or Replace a hardware device to a station.
     * Admin only.
     *
     * @param AttachStationMappingRequest $request
     * @param int $stationId
     * @return JsonResponse
     */
    public function store(AttachStationMappingRequest $request, $stationId): JsonResponse
    {
        $station = Device::find($stationId);

        if (!$station) {
            return response()->json([
                'success' => false,
                'message' => "Stasiun dengan ID {$stationId} tidak ditemukan.",
            ], 404);
        }

        $physicalDeviceId = (int) $request->input('physical_device_id');
        $mappingRole = $request->input('mapping_role');
        $attachNotes = $request->input('attach_notes');
        $detachNotes = $request->input('detach_notes');
        $shouldReplace = $request->boolean('replace') || $request->filled('detach_notes');

        try {
            // Check if active mapping already exists for this role on this station
            $existingActive = StationDeviceMapping::where('station_id', $stationId)
                ->where('mapping_role', $mappingRole)
                ->where('mapping_status', StationDeviceMapping::STATUS_ACTIVE)
                ->first();

            if ($existingActive && $shouldReplace) {
                // Explicit replacement workflow
                $mapping = $this->service->replaceHardware(
                    $stationId,
                    $physicalDeviceId,
                    $mappingRole,
                    auth()->id(),
                    $attachNotes,
                    $detachNotes
                );
            } else {
                // Standard attach workflow (throws 409 if active role already mapped)
                $mapping = $this->service->attachHardware(
                    $stationId,
                    $physicalDeviceId,
                    $mappingRole,
                    auth()->id(),
                    $attachNotes
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Perangkat fisik berhasil dipetakan ke stasiun billing.',
                'data' => [
                    'mapping_id' => $mapping->id,
                    'station_id' => $mapping->station_id,
                    'station_name' => $station->nama,
                    'physical_device_id' => $mapping->physical_device_id,
                    'mapping_role' => $mapping->mapping_role,
                    'mapping_status' => $mapping->mapping_status,
                    'attached_by_user_id' => $mapping->attached_by_user_id,
                    'attached_at' => $mapping->attached_at ? $mapping->attached_at->toIso8601String() : null,
                    'attach_notes' => $mapping->attach_notes,
                ],
            ], 201);
        } catch (StationMappingException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }
    }

    /**
     * Soft-detach hardware from a station.
     * Admin only.
     *
     * @param DetachStationMappingRequest $request
     * @param int $stationId
     * @param int $mappingId
     * @return JsonResponse
     */
    public function detach(DetachStationMappingRequest $request, $stationId, $mappingId): JsonResponse
    {
        $station = Device::find($stationId);

        if (!$station) {
            return response()->json([
                'success' => false,
                'message' => "Stasiun dengan ID {$stationId} tidak ditemukan.",
            ], 404);
        }

        $mapping = StationDeviceMapping::find($mappingId);

        if (!$mapping) {
            return response()->json([
                'success' => false,
                'message' => "Mapping dengan ID {$mappingId} tidak ditemukan.",
            ], 404);
        }

        // Integrity validation: URL stationId must match mapping record
        if ($mapping->station_id != $stationId) {
            return response()->json([
                'success' => false,
                'message' => "Mapping ID {$mappingId} tidak terdaftar pada stasiun ID {$stationId}.",
            ], 422);
        }

        // Must be active to be detached
        if ($mapping->mapping_status !== StationDeviceMapping::STATUS_ACTIVE) {
            return response()->json([
                'success' => false,
                'message' => "Mapping ID {$mappingId} sudah berstatus INACTIVE.",
            ], 409);
        }

        try {
            $detachedMapping = $this->service->softDetach(
                $stationId,
                $mapping->mapping_role,
                auth()->id(),
                $request->input('detach_notes')
            );

            return response()->json([
                'success' => true,
                'message' => 'Pemetaan hardware berhasil dilepas (soft-detach).',
                'data' => [
                    'mapping_id' => $detachedMapping->id,
                    'station_id' => $detachedMapping->station_id,
                    'mapping_status' => $detachedMapping->mapping_status,
                    'detached_by_user_id' => $detachedMapping->detached_by_user_id,
                    'detached_at' => $detachedMapping->detached_at ? $detachedMapping->detached_at->toIso8601String() : null,
                    'detach_notes' => $detachedMapping->detach_notes,
                ],
            ], 200);
        } catch (StationMappingException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }
    }
}
