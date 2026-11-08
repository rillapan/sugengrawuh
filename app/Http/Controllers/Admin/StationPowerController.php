<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\StationDeviceMapping;
use App\Services\Hardware\StationPowerControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StationPowerController extends Controller
{
    protected StationPowerControlService $powerControlService;

    public function __construct(StationPowerControlService $powerControlService)
    {
        $this->powerControlService = $powerControlService;
    }

    /**
     * Handle manual power control (ON/OFF) for a station's mapped hardware.
     *
     * @param Request $request
     * @param int $stationId
     * @return JsonResponse
     */
    public function controlPower(Request $request, int $stationId): JsonResponse
    {
        $request->validate([
            'physical_device_id' => 'required|integer|exists:physical_devices,id',
            'desired_state' => 'required|string|in:ON,OFF,on,off',
            'idempotency_key' => 'nullable|string|max:64',
        ]);

        $station = Device::findOrFail($stationId);
        $physicalDevice = PhysicalDevice::findOrFail($request->physical_device_id);

        $result = $this->powerControlService->controlPower(
            $station,
            $physicalDevice,
            strtoupper($request->desired_state),
            $request->user(),
            $request->idempotency_key
        );

        if ($result['success']) {
            return response()->json($result, 200);
        }

        // Determine appropriate HTTP status code
        $statusCode = match ($result['result_code'] ?? '') {
            'UNAUTHORIZED_ROLE' => 403,
            'INVALID_STATE', 'INVALID_MAPPING', 'DEVICE_INACTIVE', 'SAFETY_BLOCKED' => 422,
            'DEVICE_LOCKED' => 423,
            'UNSUPPORTED_CAPABILITY' => 400,
            default => 500,
        };

        return response()->json($result, $statusCode);
    }

    /**
     * Retrieve current power status of mapped hardware for a station.
     *
     * @param Request $request
     * @param int $stationId
     * @return JsonResponse
     */
    public function status(Request $request, int $stationId): JsonResponse
    {
        $station = Device::findOrFail($stationId);

        if ($request->has('physical_device_id')) {
            $physicalDevice = PhysicalDevice::findOrFail($request->physical_device_id);
            $status = $this->powerControlService->getPowerStatus($station, $physicalDevice);
            return response()->json($status);
        }

        // Return status for all active mappings of this station
        $mappings = StationDeviceMapping::where('station_id', $station->id)
            ->where('mapping_status', StationDeviceMapping::STATUS_ACTIVE)
            ->with('physicalDevice')
            ->get();

        $statuses = [];
        foreach ($mappings as $mapping) {
            if ($mapping->physicalDevice) {
                $statuses[$mapping->physical_device_id] = $this->powerControlService->getPowerStatus(
                    $station,
                    $mapping->physicalDevice
                );
            }
        }

        return response()->json([
            'station_id' => $station->id,
            'station_name' => $station->nama,
            'devices' => $statuses,
        ]);
    }
}

