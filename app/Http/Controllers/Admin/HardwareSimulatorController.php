<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\HardwareSimulationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SetSimulationScenarioRequest;
use App\Http\Requests\SimulateCommandRequest;
use App\Models\PhysicalDevice;
use App\Services\Hardware\HardwareDriverManager;
use App\Services\Hardware\Simulation\HardwareSimulationManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HardwareSimulatorController extends Controller
{
    protected HardwareDriverManager $driverManager;
    protected HardwareSimulationManager $simulationManager;

    public function __construct(HardwareDriverManager $driverManager)
    {
        $this->driverManager = $driverManager;
        $this->simulationManager = $driverManager->getSimulationManager();
    }

    /**
     * Render the Blade UI page for Hardware Simulator.
     * Admin only.
     */
    public function index(Request $request)
    {
        try {
            $this->simulationManager->assertSimulationAllowed();
        } catch (HardwareSimulationException $e) {
            abort($e->getStatusCode(), $e->getMessage());
        }

        $devices = PhysicalDevice::with(['activeMapping.station'])
            ->orderBy('id', 'desc')
            ->get();

        return view('admin.hardware.simulator', [
            'title' => 'Hardware Simulator — Mock Testing Lab',
            'active' => 'hardware-simulator',
            'devices' => $devices,
            'isSimulationEnabled' => config('hardware.simulation_enabled', false),
            'activeDriver' => config('hardware.driver', 'none'),
        ]);
    }

    public function devices(): JsonResponse
    {
        return $this->getVirtualDevices();
    }

    public function getVirtualDevices(): JsonResponse
    {
        try {
            $this->simulationManager->assertSimulationAllowed();

            $devices = PhysicalDevice::with(['activeMapping.station'])
                ->orderBy('id', 'desc')
                ->get()
                ->map(function (PhysicalDevice $device) {
                    $driver = null;
                    $supportedCommands = [];
                    try {
                        $driver = $this->driverManager->getDriverForDevice($device);
                        $supportedCommands = $driver->getSupportedCommands();
                    } catch (\Exception $e) {
                        // Incompatible or disabled
                    }

                    $mapping = $device->activeMapping;
                    $stationInfo = null;
                    if ($mapping && $mapping->station) {
                        $stationInfo = [
                            'station_id' => $mapping->station_id,
                            'station_name' => $mapping->station->nama,
                            'station_category' => $mapping->station->getStationCategory(),
                            'mapping_role' => $mapping->mapping_role,
                        ];
                    }

                    return [
                        'id' => $device->id,
                        'name' => $device->name,
                        'category' => $device->category,
                        'brand' => $device->brand,
                        'model' => $device->model,
                        'mac_address' => $device->mac_address,
                        'ip_address' => $device->ip_address,
                        'is_active' => (bool) $device->is_active,
                        'connection_status' => $device->connection_status, // Real DB status (always UNVERIFIED)
                        'station_mapping' => $stationInfo,
                        'active_scenario' => $this->simulationManager->getDeviceScenario($device->id),
                        'simulated_state' => $this->simulationManager->getSimulatedState($device->id),
                        'supported_commands' => $supportedCommands,
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'Daftar perangkat virtual berhasil diambil.',
                'data' => $devices,
            ]);
        } catch (HardwareSimulationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }
    }

    /**
     * JSON API: Set deterministic scenario for a device.
     */
    public function setScenario(SetSimulationScenarioRequest $request): JsonResponse
    {
        try {
            $this->simulationManager->assertSimulationAllowed();

            $deviceId = (int) $request->input('device_id');
            $scenario = $request->input('scenario');

            $this->simulationManager->setDeviceScenario($deviceId, $scenario);

            return response()->json([
                'success' => true,
                'message' => "Skenario simulasi untuk perangkat ID {$deviceId} berhasil diubah menjadi {$scenario}.",
                'data' => [
                    'device_id' => $deviceId,
                    'scenario' => $scenario,
                ],
            ]);
        } catch (HardwareSimulationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }
    }

    /**
     * JSON API: Execute simulated command.
     */
    public function sendCommand(SimulateCommandRequest $request): JsonResponse
    {
        try {
            $this->simulationManager->assertSimulationAllowed();

            $deviceId = (int) $request->input('device_id');
            $command = $request->input('command');

            $device = PhysicalDevice::find($deviceId);
            if (!$device) {
                throw HardwareSimulationException::deviceNotFound($deviceId);
            }

            $driver = $this->driverManager->getDriverForDevice($device);
            $result = $driver->sendCommand($device, $command);

            return response()->json([
                'success' => $result->success,
                'message' => $result->message,
                'data' => $result->toArray(),
            ], $result->success ? 200 : 422);
        } catch (HardwareSimulationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }
    }

    /**
     * JSON API: Get current simulated status for a device.
     */
    public function getStatus(int $id): JsonResponse
    {
        try {
            $this->simulationManager->assertSimulationAllowed();

            $device = PhysicalDevice::find($id);
            if (!$device) {
                throw HardwareSimulationException::deviceNotFound($id);
            }

            $driver = $this->driverManager->getDriverForDevice($device);
            $result = $driver->getStatus($device);

            return response()->json([
                'success' => $result->success,
                'message' => $result->message,
                'data' => $result->toArray(),
            ], $result->success ? 200 : 422);
        } catch (HardwareSimulationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }
    }

    /**
     * JSON API: Get event log for a device.
     */
    public function getEvents(int $id): JsonResponse
    {
        try {
            $this->simulationManager->assertSimulationAllowed();

            $events = $this->simulationManager->getEvents($id, 20);

            return response()->json([
                'success' => true,
                'message' => "Riwayat event simulasi untuk perangkat ID {$id} berhasil diambil.",
                'data' => $events,
            ]);
        } catch (HardwareSimulationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }
    }

    /**
     * JSON API: Reset simulation state and events.
     */
    public function reset(Request $request): JsonResponse
    {
        try {
            $this->simulationManager->assertSimulationAllowed();

            $deviceId = $request->input('device_id');
            if ($deviceId) {
                $this->simulationManager->resetDevice((int) $deviceId);
                $message = "Simulasi untuk perangkat ID {$deviceId} berhasil direset.";
            } else {
                $this->simulationManager->resetAll();
                $message = 'Seluruh status simulasi hardware berhasil direset ke nilai awal.';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
            ]);
        } catch (HardwareSimulationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }
    }
}
