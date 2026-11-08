<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\StationMappingException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePhysicalDeviceRequest;
use App\Http\Requests\UpdatePhysicalDeviceRequest;
use App\Models\PhysicalDevice;
use App\Services\Hardware\StationMappingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PhysicalDeviceController extends Controller
{
    protected StationMappingService $service;

    public function __construct(StationMappingService $service)
    {
        $this->service = $service;
    }

    /**
     * Render the Blade UI page for Physical Device Registry.
     * Accessible by Admin and Owner (Read-Only).
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function registryView(Request $request)
    {
        $query = PhysicalDevice::with(['activeMapping.station']);

        if ($request->filled('category')) {
            $query->where('category', $request->query('category'));
        }

        if ($request->filled('brand')) {
            $query->where('brand', $request->query('brand'));
        }

        if ($request->has('is_active') && $request->query('is_active') !== null && $request->query('is_active') !== '') {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('unmapped') && filter_var($request->query('unmapped'), FILTER_VALIDATE_BOOLEAN)) {
            $query->unmapped();
        }

        $devices = $query->orderBy('id', 'desc')->paginate(15)->withQueryString();

        return view('admin.hardware.registry', [
            'title' => 'Device Registry — Hardware Fisik',
            'active' => 'hardware-registry',
            'devices' => $devices,
            'filters' => $request->only(['category', 'brand', 'is_active', 'unmapped']),
        ]);
    }

    /**
     * Display a listing of physical hardware devices.
     * Accessible by Admin and Owner (Read-Only).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = PhysicalDevice::with(['activeMapping.station']);

        // Allowlisted filters
        if ($request->filled('category')) {
            $query->where('category', $request->query('category'));
        }

        if ($request->filled('brand')) {
            $query->where('brand', $request->query('brand'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('unmapped') && filter_var($request->query('unmapped'), FILTER_VALIDATE_BOOLEAN)) {
            $query->unmapped();
        }

        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
        $devices = $query->orderBy('name')->paginate($perPage);

        $transformedData = $devices->getCollection()->map(function (PhysicalDevice $device) {
            return [
                'id' => $device->id,
                'name' => $device->name,
                'category' => $device->category,
                'brand' => $device->brand,
                'model' => $device->model,
                'mac_address' => $device->mac_address,
                'ip_address' => $device->ip_address,
                'device_identifier' => $device->device_identifier,
                'connection_status' => $device->connection_status,
                'pairing_status' => $device->pairing_status,
                'is_active' => (bool) $device->is_active,
                'last_seen_at' => $device->last_seen_at ? $device->last_seen_at->toIso8601String() : null,
                'active_mapping' => $device->activeMapping ? [
                    'mapping_id' => $device->activeMapping->id,
                    'station_id' => $device->activeMapping->station_id,
                    'station_name' => $device->activeMapping->station ? $device->activeMapping->station->nama : null,
                    'mapping_role' => $device->activeMapping->mapping_role,
                    'attached_at' => $device->activeMapping->attached_at ? $device->activeMapping->attached_at->toIso8601String() : null,
                ] : null,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Daftar perangkat fisik berhasil diambil.',
            'data' => $transformedData,
            'meta' => [
                'current_page' => $devices->currentPage(),
                'per_page' => $devices->perPage(),
                'total' => $devices->total(),
                'last_page' => $devices->lastPage(),
            ],
        ], 200);
    }

    /**
     * Display the specified physical hardware device details.
     * Accessible by Admin and Owner (Read-Only).
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        $device = PhysicalDevice::with([
            'activeMapping.station',
            'stationMappings' => function ($q) {
                $q->with(['station', 'attachedBy', 'detachedBy'])->orderByDesc('attached_at');
            },
        ])->find($id);

        if (!$device) {
            return response()->json([
                'success' => false,
                'message' => "Perangkat fisik dengan ID {$id} tidak ditemukan.",
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Detail perangkat fisik berhasil diambil.',
            'data' => [
                'id' => $device->id,
                'name' => $device->name,
                'category' => $device->category,
                'brand' => $device->brand,
                'model' => $device->model,
                'mac_address' => $device->mac_address,
                'ip_address' => $device->ip_address,
                'device_identifier' => $device->device_identifier,
                'connection_status' => $device->connection_status,
                'pairing_status' => $device->pairing_status,
                'verified_capabilities' => $device->verified_capabilities,
                'last_seen_at' => $device->last_seen_at ? $device->last_seen_at->toIso8601String() : null,
                'is_active' => (bool) $device->is_active,
                'active_mapping' => $device->activeMapping ? [
                    'mapping_id' => $device->activeMapping->id,
                    'station_id' => $device->activeMapping->station_id,
                    'station_name' => $device->activeMapping->station ? $device->activeMapping->station->nama : null,
                    'mapping_role' => $device->activeMapping->mapping_role,
                    'attached_at' => $device->activeMapping->attached_at ? $device->activeMapping->attached_at->toIso8601String() : null,
                ] : null,
                'mapping_history' => $device->stationMappings->map(function ($m) {
                    return [
                        'mapping_id' => $m->id,
                        'station_id' => $m->station_id,
                        'station_name' => $m->station ? $m->station->nama : null,
                        'mapping_role' => $m->mapping_role,
                        'mapping_status' => $m->mapping_status,
                        'attached_by' => $m->attachedBy ? $m->attachedBy->name : null,
                        'detached_by' => $m->detachedBy ? $m->detachedBy->name : null,
                        'attached_at' => $m->attached_at ? $m->attached_at->toIso8601String() : null,
                        'detached_at' => $m->detached_at ? $m->detached_at->toIso8601String() : null,
                        'attach_notes' => $m->attach_notes,
                        'detach_notes' => $m->detach_notes,
                    ];
                }),
            ],
        ], 200);
    }

    /**
     * Store a newly created physical device in storage.
     * Admin only.
     *
     * @param StorePhysicalDeviceRequest $request
     * @return JsonResponse
     */
    public function store(StorePhysicalDeviceRequest $request): JsonResponse
    {
        // Allowlisted payload only
        $payload = $request->only([
            'name',
            'category',
            'brand',
            'model',
            'mac_address',
            'ip_address',
            'device_identifier',
        ]);

        try {
            $device = $this->service->registerPhysicalDevice($payload);

            return response()->json([
                'success' => true,
                'message' => 'Perangkat fisik berhasil didaftarkan dengan status UNVERIFIED.',
                'data' => [
                    'id' => $device->id,
                    'name' => $device->name,
                    'category' => $device->category,
                    'brand' => $device->brand,
                    'model' => $device->model,
                    'mac_address' => $device->mac_address,
                    'ip_address' => $device->ip_address,
                    'device_identifier' => $device->device_identifier,
                    'connection_status' => $device->connection_status,
                    'pairing_status' => $device->pairing_status,
                    'is_active' => (bool) $device->is_active,
                ],
            ], 201);
        } catch (StationMappingException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Update the specified physical device in storage.
     * Admin only.
     *
     * @param UpdatePhysicalDeviceRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(UpdatePhysicalDeviceRequest $request, $id): JsonResponse
    {
        // Strict allowlist: ignore any client-supplied connection_status, pairing_payload, capabilities
        $payload = $request->only([
            'name',
            'category',
            'brand',
            'model',
            'mac_address',
            'ip_address',
            'device_identifier',
            'is_active',
        ]);

        try {
            $device = $this->service->updatePhysicalDevice($id, $payload);

            return response()->json([
                'success' => true,
                'message' => 'Data perangkat fisik berhasil diperbarui.',
                'data' => [
                    'id' => $device->id,
                    'name' => $device->name,
                    'category' => $device->category,
                    'brand' => $device->brand,
                    'model' => $device->model,
                    'mac_address' => $device->mac_address,
                    'ip_address' => $device->ip_address,
                    'device_identifier' => $device->device_identifier,
                    'connection_status' => $device->connection_status,
                    'pairing_status' => $device->pairing_status,
                    'is_active' => (bool) $device->is_active,
                ],
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => "Perangkat fisik dengan ID {$id} tidak ditemukan.",
            ], 404);
        } catch (StationMappingException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Deactivate the specified physical device (soft deactivation).
     * Admin only.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy($id): JsonResponse
    {
        try {
            $this->service->deactivateHardware($id);

            return response()->json([
                'success' => true,
                'message' => 'Perangkat fisik berhasil dinonaktifkan dari inventaris.',
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => "Perangkat fisik dengan ID {$id} tidak ditemukan.",
            ], 404);
        } catch (StationMappingException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }
    }
}
