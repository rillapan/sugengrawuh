<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\HardwareSafetyException;
use App\Exceptions\HardwareSimulationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\DiagnosticCommandRequest;
use App\Http\Requests\DiagnosticPairRequest;
use App\Http\Requests\DiagnosticProbeRequest;
use App\Models\PhysicalDevice;
use App\Services\Hardware\DeviceStatusManagementService;
use App\Services\Hardware\Drivers\SamsungTvDriver;
use App\Services\Hardware\Drivers\ShellyRelayDriver;
use App\Services\Hardware\Security\HardwareNetworkSecurity;
use App\Services\Hardware\Security\HardwareSafetyGuard;
use App\Services\Hardware\Transports\FakeSamsungWebSocketTransport;
use App\Services\Hardware\Transports\FakeShellyHttpRpcTransport;
use App\Services\Hardware\Transports\SamsungWebSocketTransport;
use App\Services\Hardware\Transports\ShellyHttpRpcTransport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class HardwareDiagnosticsController extends Controller
{
    protected const EVENT_CACHE_PREFIX = 'hardware_diagnostic_events_';
    protected DeviceStatusManagementService $statusService;

    public function __construct(DeviceStatusManagementService $statusService)
    {
        $this->statusService = $statusService;
    }

    /**
     * Render the Blade UI for Hardware Diagnostics.
     * Admin only.
     */
    public function index(Request $request)
    {
        $devices = PhysicalDevice::with(['activeMapping.station'])
            ->orderBy('id', 'desc')
            ->get();

        return view('admin.hardware.diagnostics', [
            'title' => 'Hardware Diagnostics — Protocol & Hardware Lab',
            'active' => 'hardware-diagnostics',
            'devices' => $devices,
            'activeDriver' => config('hardware.driver', 'none'),
            'realExecutionEnabled' => HardwareSafetyGuard::isRealExecutionEnabled(),
        ]);
    }

    /**
     * JSON API: List all devices with diagnostic metadata.
     */
    public function devices(): JsonResponse
    {
        $devices = PhysicalDevice::with(['activeMapping.station'])
            ->orderBy('id', 'desc')
            ->get()
            ->map(function (PhysicalDevice $device) {
                $supportedCommands = [];
                $protocolInfo = '';

                if ($device->category === PhysicalDevice::CATEGORY_SMART_TV) {
                    $supportedCommands = SamsungTvDriver::SUPPORTED_COMMANDS;
                    $protocolInfo = 'Samsung Smart TV (WebSocket: 8001/8002, REST: 8001)';
                } elseif ($device->category === PhysicalDevice::CATEGORY_SMART_RELAY) {
                    $supportedCommands = ShellyRelayDriver::SUPPORTED_COMMANDS;
                    $protocolInfo = 'Shelly 1PM Mini Gen3 (HTTP JSON-RPC 2.0: 80)';
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
                    'connection_status' => $device->connection_status,
                    'pairing_status' => $device->pairing_status,
                    'verified_capabilities' => $device->verified_capabilities ?? [],
                    'station_mapping' => $stationInfo,
                    'supported_commands' => $supportedCommands,
                    'protocol_info' => $protocolInfo,
                    'real_execution_allowed' => HardwareSafetyGuard::isRealExecutionEnabled(),
                ];
            });

        return response()->json([
            'success' => true,
            'message' => 'Daftar perangkat diagnostik berhasil dimuat.',
            'data' => $devices,
        ]);
    }

    /**
     * JSON API: Run diagnostic probe.
     */
    public function probe(DiagnosticProbeRequest $request): JsonResponse
    {
        $deviceId = (int) $request->input('device_id');
        $mode = $request->input('transport_mode', 'fake');
        $scenario = $request->input('scenario', 'SUCCESS');

        $device = PhysicalDevice::find($deviceId);
        if (!$device) {
            return response()->json(['success' => false, 'message' => 'Perangkat tidak ditemukan'], 404);
        }

        try {
            if ($mode === 'real') {
                HardwareSafetyGuard::assertRealExecutionAllowed($device, 'PROBE', auth()->user(), true);
                $driver = $this->createRealDriver($device);
            } else {
                $driver = $this->createFakeDriver($device, $scenario);
            }

            $result = $driver->probe($device);
            $this->logDiagnosticEvent($deviceId, 'PROBE', $result->toArray(), $mode);

            return response()->json([
                'success' => $result->success,
                'message' => $result->message,
                'transport_mode' => $mode === 'real' ? 'REAL_NETWORK' : 'FAKE_TRANSPORT',
                'disclaimer' => $mode === 'fake' ? 'G4-B1 TEST PROTOCOL: Fake Transport response. Status fisik belum diverifikasi.' : 'REAL PHYSICAL RESPONSE',
                'data' => HardwareNetworkSecurity::sanitizeLog($result->toArray()),
            ], $result->success ? 200 : 422);
        } catch (HardwareSafetyException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'transport_mode' => $mode,
                'safety_guard' => 'BLOCKED',
            ], $e->getStatusCode());
        } catch (HardwareSimulationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }
    }

    /**
     * JSON API: Run diagnostic pairing handshake.
     */
    public function pair(DiagnosticPairRequest $request): JsonResponse
    {
        $deviceId = (int) $request->input('device_id');
        $mode = $request->input('transport_mode', 'fake');
        $password = $request->input('password');
        $scenario = $request->input('scenario', 'SUCCESS');

        $device = PhysicalDevice::find($deviceId);
        if (!$device) {
            return response()->json(['success' => false, 'message' => 'Perangkat tidak ditemukan'], 404);
        }

        try {
            if ($mode === 'real') {
                HardwareSafetyGuard::assertRealExecutionAllowed($device, 'PAIR', auth()->user(), true);
                $driver = $this->createRealDriver($device);
            } else {
                $driver = $this->createFakeDriver($device, $scenario);
            }

            $forceNew = $request->boolean('force_new', false);
            $result = $driver->pair($device, ['password' => $password, 'force_new' => $forceNew]);
            $this->logDiagnosticEvent($deviceId, 'PAIR', $result->toArray(), $mode);

            return response()->json([
                'success' => $result->success,
                'message' => $result->message,
                'transport_mode' => $mode === 'real' ? 'REAL_NETWORK' : 'FAKE_TRANSPORT',
                'disclaimer' => $mode === 'fake' ? 'G4-B1 TEST PROTOCOL: Fake Transport response. Token tidak disimpan pada database perangkat nyata.' : 'REAL PHYSICAL RESPONSE',
                'data' => HardwareNetworkSecurity::sanitizeLog($result->toArray()),
            ], $result->success ? 200 : 422);
        } catch (HardwareSafetyException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'safety_guard' => 'BLOCKED',
            ], $e->getStatusCode());
        } catch (HardwareSimulationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }
    }

    /**
     * JSON API: Send diagnostic command.
     */
    public function sendCommand(DiagnosticCommandRequest $request): JsonResponse
    {
        $deviceId = (int) $request->input('device_id');
        $command = $request->input('command');
        $mode = $request->input('transport_mode', 'fake');
        $scenario = $request->input('scenario', 'SUCCESS');

        $device = PhysicalDevice::find($deviceId);
        if (!$device) {
            return response()->json(['success' => false, 'message' => 'Perangkat tidak ditemukan'], 404);
        }

        try {
            if ($mode === 'real') {
                HardwareSafetyGuard::assertRealExecutionAllowed($device, $command, auth()->user(), false);
                $driver = $this->createRealDriver($device);
            } else {
                $driver = $this->createFakeDriver($device, $scenario);
            }

            $result = $driver->sendCommand($device, $command);
            $this->logDiagnosticEvent($deviceId, $command, $result->toArray(), $mode);

            return response()->json([
                'success' => $result->success,
                'message' => $result->message,
                'transport_mode' => $mode === 'real' ? 'REAL_NETWORK' : 'FAKE_TRANSPORT',
                'disclaimer' => $mode === 'fake' ? 'G4-B1 TEST PROTOCOL: Fake Transport execution.' : 'REAL PHYSICAL RESPONSE',
                'data' => HardwareNetworkSecurity::sanitizeLog($result->toArray()),
            ], $result->success ? 200 : 422);
        } catch (HardwareSafetyException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'safety_guard' => 'BLOCKED',
            ], $e->getStatusCode());
        } catch (HardwareSimulationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }
    }

    /**
     * JSON API: Get current diagnostic status of a device.
     */
    public function getStatus(int $id): JsonResponse
    {
        $device = PhysicalDevice::find($id);
        if (!$device) {
            return response()->json(['success' => false, 'message' => 'Perangkat tidak ditemukan'], 404);
        }

        $driver = $this->createFakeDriver($device, 'SUCCESS');
        $result = $driver->getStatus($device);

        return response()->json([
            'success' => $result->success,
            'message' => $result->message,
            'transport_mode' => 'FAKE_TRANSPORT',
            'data' => HardwareNetworkSecurity::sanitizeLog($result->toArray()),
        ]);
    }

    /**
     * JSON API: Get recent diagnostic event log for a device.
     */
    public function getEvents(int $id): JsonResponse
    {
        $cacheKey = self::EVENT_CACHE_PREFIX . $id;
        $events = Cache::get($cacheKey, []);

        return response()->json([
            'success' => true,
            'message' => 'Riwayat diagnostik berhasil diambil',
            'data' => $events,
        ]);
    }

    protected function createFakeDriver(PhysicalDevice $device, string $scenario)
    {
        if ($device->category === PhysicalDevice::CATEGORY_SMART_TV) {
            $transport = new FakeSamsungWebSocketTransport($scenario);
            return new SamsungTvDriver($transport, $this->statusService, false);
        }

        if ($device->category === PhysicalDevice::CATEGORY_SMART_RELAY) {
            $transport = new FakeShellyHttpRpcTransport($scenario);
            return new ShellyRelayDriver($transport, $this->statusService, false);
        }

        throw HardwareSimulationException::incompatibleDevice('SMART_TV atau SMART_RELAY', $device->category);
    }

    protected function createRealDriver(PhysicalDevice $device)
    {
        $isRealVerified = HardwareSafetyGuard::isRealExecutionEnabled();

        if ($device->category === PhysicalDevice::CATEGORY_SMART_TV) {
            return new SamsungTvDriver(new SamsungWebSocketTransport(), $this->statusService, $isRealVerified);
        }

        if ($device->category === PhysicalDevice::CATEGORY_SMART_RELAY) {
            return new ShellyRelayDriver(new ShellyHttpRpcTransport(), $this->statusService, $isRealVerified);
        }

        throw HardwareSimulationException::incompatibleDevice('SMART_TV atau SMART_RELAY', $device->category);
    }

    protected function logDiagnosticEvent(int $deviceId, string $action, array $result, string $mode): void
    {
        $cacheKey = self::EVENT_CACHE_PREFIX . $deviceId;
        $events = Cache::get($cacheKey, []);

        $cleanResult = HardwareNetworkSecurity::sanitizeLog($result);

        array_unshift($events, [
            'action' => $action,
            'mode' => $mode,
            'success' => $cleanResult['success'] ?? false,
            'result_code' => $cleanResult['result_code'] ?? 'UNKNOWN',
            'message' => $cleanResult['message'] ?? '',
            'timestamp' => now()->toIso8601String(),
            'executed_by' => auth()->user() ? auth()->user()->name : 'System',
        ]);

        // Keep last 30 events
        $events = array_slice($events, 0, 30);
        Cache::put($cacheKey, $events, now()->addDays(7));
    }
}
