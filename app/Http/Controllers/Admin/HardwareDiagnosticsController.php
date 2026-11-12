<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\HardwareSafetyException;
use App\Exceptions\HardwareSimulationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\DiagnosticCommandRequest;
use App\Models\PhysicalDevice;
use App\Services\Hardware\DeviceStatusManagementService;
use App\Services\Hardware\Drivers\SamsungTvDriver;
use App\Services\Hardware\Drivers\ShellyRelayDriver;
use App\Services\Hardware\DTO\HardwareCommandResult;
use App\Services\Hardware\Security\HardwareNetworkSecurity;
use App\Services\Hardware\Security\HardwareSafetyGuard;
use App\Services\Hardware\Transports\FakeSamsungWebSocketTransport;
use App\Services\Hardware\Transports\FakeShellyHttpRpcTransport;
use App\Services\Hardware\Transports\SamsungTransportInterface;
use App\Services\Hardware\Transports\SamsungWebSocketTransport;
use App\Services\Hardware\Transports\ShellyHttpRpcTransport;
use App\Services\Hardware\Transports\ShellyTransportInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class HardwareDiagnosticsController extends Controller
{
    protected const EVENT_CACHE_PREFIX = 'hardware_diagnostic_events_';

    // 7 Canonical Diagnostic Statuses (Gate G2)
    public const STATUS_CONNECTED = 'Connected';
    public const STATUS_DISCONNECTED = 'Disconnected';
    public const STATUS_AUTH_REQUIRED = 'Authentication Required';
    public const STATUS_PAIRING_REQUIRED = 'Pairing Required';
    public const STATUS_DRIVER_UNSUPPORTED = 'Driver Unsupported';
    public const STATUS_TIMEOUT = 'Timeout';
    public const STATUS_CONFIG_ERROR = 'Configuration Error';

    public const CANONICAL_STATUSES = [
        self::STATUS_CONNECTED,
        self::STATUS_DISCONNECTED,
        self::STATUS_AUTH_REQUIRED,
        self::STATUS_PAIRING_REQUIRED,
        self::STATUS_DRIVER_UNSUPPORTED,
        self::STATUS_TIMEOUT,
        self::STATUS_CONFIG_ERROR,
    ];

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
            'canonicalStatuses' => self::CANONICAL_STATUSES,
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

                if ($device->category === PhysicalDevice::CATEGORY_SMART_TV && $device->brand === PhysicalDevice::BRAND_SAMSUNG) {
                    $supportedCommands = SamsungTvDriver::SUPPORTED_COMMANDS;
                    $protocolInfo = 'Samsung Smart TV (WebSocket: 8001/8002, REST: 8001)';
                } elseif ($device->category === PhysicalDevice::CATEGORY_SMART_RELAY && $device->brand === PhysicalDevice::BRAND_SHELLY) {
                    $supportedCommands = ShellyRelayDriver::SUPPORTED_COMMANDS;
                    $protocolInfo = 'Shelly 1PM Mini Gen3 (HTTP JSON-RPC 2.0: 80)';
                } else {
                    $protocolInfo = "Driver belum terverifikasi ({$device->brand})";
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
     * JSON API: Run read-only diagnostic probe.
     */
    public function probe(Request $request): JsonResponse
    {
        $request->validate([
            'device_id' => ['required', 'integer', 'exists:physical_devices,id'],
            'transport_mode' => ['nullable', 'string', 'in:fake,real'],
            'scenario' => ['nullable', 'string', 'max:50'],
            'fake_scenario' => ['nullable', 'string', 'max:50'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ]);

        $deviceId = (int) $request->input('device_id');
        $mode = $request->input('transport_mode', 'fake');
        $scenario = strtoupper((string) ($request->input('fake_scenario') ?? $request->input('scenario', 'SUCCESS')));
        $port = $request->filled('port') ? (int) $request->input('port') : null;

        $device = PhysicalDevice::find($deviceId);
        if (!$device) {
            return response()->json(['success' => false, 'message' => 'Perangkat tidak ditemukan'], 404);
        }

        try {
            if ($mode === 'real') {
                HardwareSafetyGuard::assertReadOnlyDiagnosticAllowed($device, 'PROBE', auth()->user(), $port);
            }

            if (!$this->isSupportedDiagnosticDevice($device)) {
                $result = $this->createDriverNotAvailableResult($device, 'PROBE', $mode !== 'real');
            } else {
                $driver = $mode === 'real'
                    ? $this->createRealDriver($device, false, false)
                    : $this->createFakeDriver($device, $scenario);
                $result = $driver->probe($device);
            }

            $report = self::buildDiagnosticReport($device, $result, 'PROBE');
            $this->logDiagnosticEvent($deviceId, 'PROBE', $result->toArray(), $mode);

            $sanitizedData = HardwareNetworkSecurity::sanitizeLog($result->toArray());
            $sanitizedData['diagnostic_status'] = $report['status'];
            $sanitizedData['diagnostic_report'] = $report;

            return response()->json([
                'success' => $result->success,
                'message' => $result->message,
                'transport_mode' => $mode === 'real' ? 'REAL_NETWORK' : 'FAKE_TRANSPORT',
                'disclaimer' => $mode === 'fake' ? 'G4-B1 TEST PROTOCOL: Fake Transport response. Status fisik belum diverifikasi.' : 'REAL PHYSICAL READ-ONLY DIAGNOSTIC RESPONSE',
                'diagnostic_status' => $report['status'],
                'diagnostic_report' => $report,
                'data' => $sanitizedData,
            ], $result->success ? 200 : 422);
        } catch (HardwareSafetyException $e) {
            return $this->formatSafetyExceptionResponse($e, $device, 'PROBE', $mode);
        } catch (HardwareSimulationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'diagnostic_status' => self::STATUS_DRIVER_UNSUPPORTED,
            ], $e->getStatusCode());
        }
    }

    /**
     * JSON API: Run diagnostic pairing handshake (ADMIN only, saves encrypted token without auto-enabling automation).
     */
    public function pair(Request $request): JsonResponse
    {
        $request->validate([
            'device_id' => ['required', 'integer', 'exists:physical_devices,id'],
            'transport_mode' => ['nullable', 'string', 'in:fake,real'],
            'password' => ['nullable', 'string', 'max:100'],
            'scenario' => ['nullable', 'string', 'max:50'],
            'fake_scenario' => ['nullable', 'string', 'max:50'],
            'force_new' => ['nullable', 'boolean'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ]);

        $deviceId = (int) $request->input('device_id');
        $mode = $request->input('transport_mode', 'fake');
        $password = $request->input('password');
        $scenario = strtoupper((string) ($request->input('fake_scenario') ?? $request->input('scenario', 'SUCCESS')));
        $port = $request->filled('port') ? (int) $request->input('port') : null;

        $device = PhysicalDevice::find($deviceId);
        if (!$device) {
            return response()->json(['success' => false, 'message' => 'Perangkat tidak ditemukan'], 404);
        }

        try {
            if ($mode === 'real') {
                HardwareSafetyGuard::assertPairingAllowed($device, auth()->user(), $port);
            }

            if (!$this->isSupportedDiagnosticDevice($device)) {
                $result = $this->createDriverNotAvailableResult($device, 'PAIR', $mode !== 'real');
            } else {
                $driver = $mode === 'real'
                    ? $this->createRealDriver($device, false, true)
                    : $this->createFakeDriver($device, $scenario);

                $forceNew = $request->boolean('force_new', false);
                $result = $driver->pair($device, [
                    'password' => $password,
                    'force_new' => $forceNew,
                    'authorized_pairing' => ($mode === 'real'),
                    'port' => $port,
                ]);

                // Ensure Shelly pairing in real mode also records encrypted password / failure via DeviceStatusManagementService
                if ($mode === 'real' && $device->brand === PhysicalDevice::BRAND_SHELLY) {
                    if ($result->success) {
                        $this->statusService->recordSuccessfulPairing($device, (string) ($password ?? ''), false, true);
                    } elseif ($result->resultCode === 'COMMAND_REJECTED' || $result->resultCode === 'AUTH_REQUIRED') {
                        $this->statusService->recordPairingFailure($device, $result->message, false, true);
                    }
                }
            }

            $report = self::buildDiagnosticReport($device, $result, 'PAIR');
            $this->logDiagnosticEvent($deviceId, 'PAIR', $result->toArray(), $mode);

            $sanitizedData = HardwareNetworkSecurity::sanitizeLog($result->toArray());
            $sanitizedData['diagnostic_status'] = $report['status'];
            $sanitizedData['diagnostic_report'] = $report;

            return response()->json([
                'success' => $result->success,
                'message' => $result->message,
                'transport_mode' => $mode === 'real' ? 'REAL_NETWORK' : 'FAKE_TRANSPORT',
                'disclaimer' => $mode === 'fake'
                    ? 'G4-B1 TEST PROTOCOL: Fake Transport response. Token tidak disimpan pada database perangkat nyata.'
                    : 'REAL PAIRING RESPONSE: Token terenkripsi disimpan. Otomasi fisik tetap menunggu verifikasi eksplisit.',
                'diagnostic_status' => $report['status'],
                'diagnostic_report' => $report,
                'data' => $sanitizedData,
            ], $result->success ? 200 : 422);
        } catch (HardwareSafetyException $e) {
            return $this->formatSafetyExceptionResponse($e, $device, 'PAIR', $mode);
        } catch (HardwareSimulationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'diagnostic_status' => self::STATUS_DRIVER_UNSUPPORTED,
            ], $e->getStatusCode());
        }
    }

    /**
     * JSON API: Issue a server-side approval token for a single bounded physical control test.
     */
    public function approvePhysicalControl(Request $request): JsonResponse
    {
        $request->validate([
            'device_id' => ['required', 'integer', 'exists:physical_devices,id'],
            'command' => ['required', 'string', 'max:50'],
            'ttl_seconds' => ['nullable', 'integer', 'min:30', 'max:600'],
        ]);

        $deviceId = (int) $request->input('device_id');
        $command = (string) $request->input('command');
        $ttlSeconds = (int) $request->input('ttl_seconds', 300);

        $device = PhysicalDevice::find($deviceId);
        if (!$device) {
            return response()->json(['success' => false, 'message' => 'Perangkat tidak ditemukan'], 404);
        }

        try {
            $approval = HardwareSafetyGuard::grantPhysicalControlApproval($device, auth()->user(), $command, $ttlSeconds);

            return response()->json([
                'success' => true,
                'message' => "Token persetujuan uji kontrol fisik ('{$approval['command']}') berhasil diterbitkan untuk perangkat '{$device->name}'.",
                'approval_token' => $approval['approval_token'],
                'expires_at' => $approval['expires_at'],
                'ttl_seconds' => $approval['ttl_seconds'],
                'data' => $approval,
            ]);
        } catch (HardwareSafetyException $e) {
            return $this->formatSafetyExceptionResponse($e, $device, $command, 'real');
        }
    }

    /**
     * JSON API: Send diagnostic command.
     */
    public function sendCommand(DiagnosticCommandRequest $request): JsonResponse
    {
        $deviceId = (int) $request->input('device_id');
        $command = (string) $request->input('command');
        $upperCommand = strtoupper(trim($command));
        $mode = $request->input('transport_mode', 'fake');
        $scenario = strtoupper((string) ($request->input('fake_scenario') ?? $request->input('scenario', 'SUCCESS')));
        $port = $request->filled('port') ? (int) $request->input('port') : null;

        $device = PhysicalDevice::find($deviceId);
        if (!$device) {
            return response()->json(['success' => false, 'message' => 'Perangkat tidak ditemukan'], 404);
        }

        try {
            if ($mode === 'real') {
                if ($upperCommand === 'GET_STATUS') {
                    HardwareSafetyGuard::assertReadOnlyDiagnosticAllowed($device, 'GET_STATUS', auth()->user(), $port);
                } else {
                    HardwareSafetyGuard::assertPhysicalControlTestAllowed(
                        $device,
                        $upperCommand,
                        auth()->user(),
                        $request->input('approval_token'),
                        true,
                        $port
                    );
                }
            }

            if (!$this->isSupportedDiagnosticDevice($device)) {
                $result = $this->createDriverNotAvailableResult($device, $upperCommand, $mode !== 'real');
            } else {
                $driver = $mode === 'real'
                    ? $this->createRealDriver($device, false, false)
                    : $this->createFakeDriver($device, $scenario);
                $result = $driver->sendCommand($device, $upperCommand);
            }

            $report = self::buildDiagnosticReport($device, $result, $upperCommand);
            $this->logDiagnosticEvent($deviceId, $upperCommand, $result->toArray(), $mode);

            $sanitizedData = HardwareNetworkSecurity::sanitizeLog($result->toArray());
            $sanitizedData['diagnostic_status'] = $report['status'];
            $sanitizedData['diagnostic_report'] = $report;

            return response()->json([
                'success' => $result->success,
                'message' => $result->message,
                'transport_mode' => $mode === 'real' ? 'REAL_NETWORK' : 'FAKE_TRANSPORT',
                'disclaimer' => $mode === 'fake' ? 'G4-B1 TEST PROTOCOL: Fake Transport execution.' : 'REAL PHYSICAL RESPONSE',
                'diagnostic_status' => $report['status'],
                'diagnostic_report' => $report,
                'data' => $sanitizedData,
            ], $result->success ? 200 : 422);
        } catch (HardwareSafetyException $e) {
            return $this->formatSafetyExceptionResponse($e, $device, $upperCommand, $mode);
        } catch (HardwareSimulationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'diagnostic_status' => self::STATUS_DRIVER_UNSUPPORTED,
            ], $e->getStatusCode());
        }
    }

    /**
     * JSON API: Get current diagnostic status of a device (Read-Only).
     */
    public function getStatus(Request $request, int $id): JsonResponse
    {
        $device = PhysicalDevice::find($id);
        if (!$device) {
            return response()->json(['success' => false, 'message' => 'Perangkat tidak ditemukan'], 404);
        }

        $mode = (string) $request->input('transport_mode', 'fake');
        $scenario = strtoupper((string) ($request->input('fake_scenario') ?? $request->input('scenario', 'SUCCESS')));
        $port = $request->filled('port') ? (int) $request->input('port') : null;

        try {
            if ($mode === 'real') {
                HardwareSafetyGuard::assertReadOnlyDiagnosticAllowed($device, 'GET_STATUS', auth()->user(), $port);
            }

            if (!$this->isSupportedDiagnosticDevice($device)) {
                $result = $this->createDriverNotAvailableResult($device, 'GET_STATUS', $mode !== 'real');
            } else {
                $driver = $mode === 'real'
                    ? $this->createRealDriver($device, false, false)
                    : $this->createFakeDriver($device, $scenario);
                $result = $driver->getStatus($device);
            }

            $report = self::buildDiagnosticReport($device, $result, 'GET_STATUS');
            $this->logDiagnosticEvent($device->id, 'GET_STATUS', $result->toArray(), $mode);

            $sanitizedData = HardwareNetworkSecurity::sanitizeLog($result->toArray());
            $sanitizedData['diagnostic_status'] = $report['status'];
            $sanitizedData['diagnostic_report'] = $report;

            return response()->json([
                'success' => $result->success,
                'message' => $result->message,
                'transport_mode' => $mode === 'real' ? 'REAL_NETWORK' : 'FAKE_TRANSPORT',
                'diagnostic_status' => $report['status'],
                'diagnostic_report' => $report,
                'data' => $sanitizedData,
            ]);
        } catch (HardwareSafetyException $e) {
            return $this->formatSafetyExceptionResponse($e, $device, 'GET_STATUS', $mode);
        } catch (HardwareSimulationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'diagnostic_status' => self::STATUS_DRIVER_UNSUPPORTED,
            ], $e->getStatusCode());
        }
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

    /**
     * Build standardized Gate G2 diagnostic report mapping to the 7 canonical statuses.
     */
    public static function buildDiagnosticReport(PhysicalDevice $device, HardwareCommandResult $result, string $operation): array
    {
        $code = strtoupper(trim($result->resultCode));
        $brand = strtoupper(trim((string) $device->brand));
        $category = strtoupper(trim((string) $device->category));

        $capabilitiesSummary = [];
        if ($category === PhysicalDevice::CATEGORY_SMART_TV && $brand === PhysicalDevice::BRAND_SAMSUNG) {
            $capabilitiesSummary = [
                'GET_STATUS' => 'SUPPORTED (REST API 8001)',
                'PAIR' => 'SUPPORTED (WSS Handshake 8002)',
                'MUTE' => 'SUPPORTED (KEY_MUTE Toggle)',
                'STANDBY' => 'SUPPORTED (KEY_POWER)',
                'POWER_ON' => !empty($device->mac_address) ? 'REQUIRES_WOL_VERIFICATION' : 'MAC_REQUIRED_FOR_WOL',
                'SET_VOLUME' => 'CAPABILITY_INCOMPATIBLE (Use VOLUME_UP / VOLUME_DOWN)',
            ];
        } elseif ($category === PhysicalDevice::CATEGORY_SMART_RELAY && $brand === PhysicalDevice::BRAND_SHELLY) {
            $capabilitiesSummary = [
                'GET_STATUS' => 'SUPPORTED (Switch.GetStatus)',
                'SWITCH_ON' => 'SUPPORTED (Switch.Set on:true + Read-Back)',
                'SWITCH_OFF' => 'SUPPORTED (Switch.Set on:false + Read-Back)',
            ];
        }

        // 1. Driver Unsupported: distinguish DRIVER_NOT_AVAILABLE vs CAPABILITY_INCOMPATIBLE
        if ($code === 'DRIVER_NOT_AVAILABLE' || $brand === 'COOCAA') {
            $brandLabel = $brand === 'COOCAA' ? 'Coocaa' : ($device->brand ?: 'perangkat ini');
            return [
                'status' => self::STATUS_DRIVER_UNSUPPORTED,
                'sub_status' => 'DRIVER_NOT_AVAILABLE',
                'operation' => $operation,
                'brand' => $device->brand,
                'category' => $device->category,
                'explanation' => "Driver belum didukung: Protokol kontrol untuk merek {$brandLabel} belum terverifikasi pada rilis ini. Hal ini bukan berarti seluruh model {$brandLabel} tidak dapat dikendalikan.",
                'remediation' => "Gunakan perangkat yang saat ini telah memiliki driver terverifikasi (Samsung Smart TV atau Shelly 1PM Mini Gen3), atau tunggu pembaruan modul driver {$brandLabel} pada rilis berikutnya.",
                'capabilities_summary' => $capabilitiesSummary,
            ];
        }

        if (in_array($code, ['CAPABILITY_UNSUPPORTED', 'INVALID_COMMAND'], true)) {
            return [
                'status' => self::STATUS_DRIVER_UNSUPPORTED,
                'sub_status' => 'CAPABILITY_INCOMPATIBLE',
                'operation' => $operation,
                'brand' => $device->brand,
                'category' => $device->category,
                'explanation' => $result->message ?: "Perintah atau kapabilitas '{$operation}' terbukti tidak kompatibel dengan protokol perangkat {$device->brand} ({$device->model}).",
                'remediation' => 'Gunakan perintah alternatif yang didukung oleh protokol perangkat (misalnya VOLUME_UP/VOLUME_DOWN atau MUTE untuk Samsung WebSocket, atau verifikasi kapabilitas WOL terlebih dahulu).',
                'capabilities_summary' => $capabilitiesSummary,
            ];
        }

        // 2. Timeout
        if (in_array($code, ['TIMEOUT', 'HANDSHAKE_TIMEOUT', 'RPC_TIMEOUT'], true)) {
            return [
                'status' => self::STATUS_TIMEOUT,
                'sub_status' => $brand === PhysicalDevice::BRAND_SAMSUNG ? 'HANDSHAKE_TIMEOUT' : 'RPC_TIMEOUT',
                'operation' => $operation,
                'brand' => $device->brand,
                'category' => $device->category,
                'explanation' => "Waktu tunggu koneksi habis saat menghubungi perangkat pada alamat IP {$device->ip_address}. Perangkat mungkin sibuk, kualitas sinyal Wi-Fi lemah, atau paket tertahan di jaringan.",
                'remediation' => "Periksa kekuatan sinyal Wi-Fi/kabel LAN perangkat, pastikan IP {$device->ip_address} benar dan bebas konflik, lalu coba jalankan ulang diagnostik.",
                'capabilities_summary' => $capabilitiesSummary,
            ];
        }

        // 3. Disconnected
        if (in_array($code, ['OFFLINE', 'DISCONNECTED', 'DEVICE_UNREACHABLE', 'TCP_CONNECT_FAILED', 'HTTP_CONNECT_FAILED', 'DEVICE_STANDBY'], true)) {
            $subStatus = $code === 'DEVICE_STANDBY'
                ? 'DEVICE_STANDBY'
                : ($brand === PhysicalDevice::BRAND_SAMSUNG ? 'TCP_CONNECT_FAILED' : 'HTTP_CONNECT_FAILED');

            return [
                'status' => self::STATUS_DISCONNECTED,
                'sub_status' => $subStatus,
                'operation' => $operation,
                'brand' => $device->brand,
                'category' => $device->category,
                'explanation' => "Perangkat pada alamat IP {$device->ip_address} tidak merespons (Offline / Unreachable). Soket jaringan menolak koneksi atau perangkat sedang dalam keadaan mati.",
                'remediation' => "Pastikan perangkat menyala, terhubung ke subnet LAN/Wi-Fi yang sama dengan kasir, dan alamat IP {$device->ip_address} telah dikunci (DHCP Static Lease) di router.",
                'capabilities_summary' => $capabilitiesSummary,
            ];
        }

        // 4. Pairing Required (Samsung TV) vs 5. Authentication Required (Shelly Relay)
        if (in_array($code, ['AUTH_REQUIRED', 'AUTH_REJECTED', 'AUTH_FAILED', 'COMMAND_REJECTED', 'PAIRING_REQUIRED', 'PAIRING_DENIED', 'PAIRING_REJECTED', 'PAIRING_FAILED'], true)) {
            if ($brand === PhysicalDevice::BRAND_SAMSUNG) {
                $subStatus = in_array($code, ['COMMAND_REJECTED', 'AUTH_REJECTED', 'PAIRING_DENIED', 'PAIRING_REJECTED'], true)
                    ? 'PAIRING_DENIED'
                    : 'PAIRING_REQUIRED';

                return [
                    'status' => self::STATUS_PAIRING_REQUIRED,
                    'sub_status' => $subStatus,
                    'operation' => $operation,
                    'brand' => $device->brand,
                    'category' => $device->category,
                    'explanation' => 'Otorisasi pairing diperlukan atau ditolak oleh Samsung Smart TV. Pengguna mungkin memilih Deny pada layar TV atau token yang tersimpan sudah tidak berlaku.',
                    'remediation' => 'Klik tombol "Pair / Handshake" pada bagian Device Pairing, lalu tekan tombol "Allow / Izinkan" menggunakan remote fisik TV saat popup otorisasi muncul di layar.',
                    'capabilities_summary' => $capabilitiesSummary,
                ];
            }

            return [
                'status' => self::STATUS_AUTH_REQUIRED,
                'sub_status' => 'HTTP_401',
                'operation' => $operation,
                'brand' => $device->brand,
                'category' => $device->category,
                'explanation' => "Perangkat Shelly 1PM Mini Gen3 pada IP {$device->ip_address} meminta autentikasi sandi HTTP Digest (HTTP 401 Unauthorized) atau sandi yang dimasukkan tidak cocok.",
                'remediation' => 'Masukkan sandi perangkat Shelly yang benar pada kolom Sandi Autentikasi Shelly, lalu jalankan fitur Pair untuk menyimpan kredensial terenkripsi.',
                'capabilities_summary' => $capabilitiesSummary,
            ];
        }

        // 6. Configuration Error (Malformed JSON, invalid response, state mismatch, etc.)
        if (!$result->success || in_array($code, ['INVALID_JSON', 'MALFORMED_RESPONSE', 'INVALID_JSON_PAYLOAD', 'MISSING_IP', 'UNSAFE_ACTION_BLOCKED', 'STATE_MISMATCH', 'INVALID_CHANNEL', 'DEVICE_ERROR', 'SECURITY_VIOLATION'], true)) {
            $subStatus = in_array($code, ['INVALID_JSON', 'MALFORMED_RESPONSE', 'INVALID_JSON_PAYLOAD'], true)
                ? 'INVALID_JSON_PAYLOAD'
                : ($code ?: 'CONFIGURATION_ERROR');

            return [
                'status' => self::STATUS_CONFIG_ERROR,
                'sub_status' => $subStatus,
                'operation' => $operation,
                'brand' => $device->brand,
                'category' => $device->category,
                'explanation' => "Terjadi kesalahan konfigurasi atau format respons protokol tidak valid ({$subStatus}) dari alamat IP {$device->ip_address}. Target IP mungkin menunjuk ke perangkat yang salah.",
                'remediation' => 'Periksa kembali alamat IP, port, kategori/merek perangkat di Device Registry, serta pastikan pemetaan station (Station Mapping) sudah sesuai aturan keselamatan.',
                'capabilities_summary' => $capabilitiesSummary,
            ];
        }

        // 7. Connected
        return [
            'status' => self::STATUS_CONNECTED,
            'sub_status' => 'CONNECTED',
            'operation' => $operation,
            'brand' => $device->brand,
            'category' => $device->category,
            'explanation' => "Perangkat {$device->name} ({$device->brand}) pada alamat IP {$device->ip_address} terhubung dan merespons protokol diagnostik dengan normal.",
            'remediation' => 'Tidak diperlukan tindakan perbaikan. Koneksi dan protokol perangkat berfungsi dengan baik.',
            'capabilities_summary' => $capabilitiesSummary,
        ];
    }

    protected function isSupportedDiagnosticDevice(PhysicalDevice $device): bool
    {
        if ($device->category === PhysicalDevice::CATEGORY_SMART_TV && $device->brand === PhysicalDevice::BRAND_SAMSUNG) {
            return true;
        }

        if ($device->category === PhysicalDevice::CATEGORY_SMART_RELAY && $device->brand === PhysicalDevice::BRAND_SHELLY) {
            return true;
        }

        return false;
    }

    protected function createDriverNotAvailableResult(PhysicalDevice $device, string $operation, bool $simulationMode): HardwareCommandResult
    {
        $brandLabel = strtoupper(trim((string) $device->brand)) === 'COOCAA' ? 'Coocaa' : ($device->brand ?: 'Unknown');
        $explanation = "Driver belum didukung: Protokol kontrol untuk merek {$brandLabel} belum terverifikasi pada rilis ini. Hal ini bukan berarti seluruh model {$brandLabel} tidak dapat dikendalikan.";

        return HardwareCommandResult::createError(
            (int) $device->id,
            'UNSUPPORTED_DRIVER',
            $operation,
            $explanation,
            'DRIVER_NOT_AVAILABLE',
            [
                'brand' => $device->brand,
                'category' => $device->category,
                'driver_status' => 'DRIVER_NOT_AVAILABLE',
            ],
            $simulationMode
        );
    }

    protected function formatSafetyExceptionResponse(HardwareSafetyException $e, PhysicalDevice $device, string $operation, string $mode): JsonResponse
    {
        $report = [
            'status' => self::STATUS_CONFIG_ERROR,
            'sub_status' => $e->getStatusCode() === 422 ? 'UNSAFE_ACTION_BLOCKED' : 'SAFETY_GUARD_BLOCKED',
            'operation' => $operation,
            'brand' => $device->brand,
            'category' => $device->category,
            'explanation' => $e->getMessage(),
            'remediation' => 'Pastikan alamat IP perangkat berada pada subnet privat RFC 1918 yang valid, port sesuai allowlist, dan gunakan token persetujuan server (approval_token) untuk uji kontrol fisik.',
        ];

        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
            'transport_mode' => $mode,
            'safety_guard' => 'BLOCKED',
            'diagnostic_status' => $report['status'],
            'diagnostic_report' => $report,
        ], $e->getStatusCode());
    }

    protected function createFakeDriver(PhysicalDevice $device, string $scenario)
    {
        $upperScenario = strtoupper(trim($scenario));

        if ($device->category === PhysicalDevice::CATEGORY_SMART_TV && $device->brand === PhysicalDevice::BRAND_SAMSUNG) {
            $mappedScenario = match ($upperScenario) {
                'OFFLINE', 'DISCONNECTED' => FakeSamsungWebSocketTransport::SCENARIO_OFFLINE,
                'TIMEOUT' => FakeSamsungWebSocketTransport::SCENARIO_TIMEOUT,
                'AUTH_REJECTED', 'AUTH_REQUIRED', 'REJECTED', 'PAIRING_DENIED', 'PAIRING_REQUIRED', 'INVALID_CREDENTIALS' => FakeSamsungWebSocketTransport::SCENARIO_AUTH_REJECTED,
                'MALFORMED', 'INVALID_JSON' => FakeSamsungWebSocketTransport::SCENARIO_MALFORMED,
                default => FakeSamsungWebSocketTransport::SCENARIO_SUCCESS,
            };

            $transport = new class($mappedScenario) extends FakeSamsungWebSocketTransport {
                public function checkConnection(string $ip, int $port = 8001, int $timeoutMs = 3000): array
                {
                    if ($this->scenario === self::SCENARIO_AUTH_REJECTED) {
                        $this->connectionAttempts[] = ['ip' => $ip, 'port' => $port, 'method' => 'checkConnection'];
                        return [
                            'success' => false,
                            'status' => 'AUTH_REJECTED',
                            'latency_ms' => 4,
                            'data' => [],
                            'message' => "Fake Transport: Pairing/Authorization required or rejected on {$ip}:{$port}",
                        ];
                    }

                    if ($this->scenario === self::SCENARIO_MALFORMED) {
                        $this->connectionAttempts[] = ['ip' => $ip, 'port' => $port, 'method' => 'checkConnection'];
                        return [
                            'success' => false,
                            'status' => 'MALFORMED_RESPONSE',
                            'latency_ms' => 4,
                            'data' => [],
                            'message' => "Fake Transport: Invalid JSON response from {$ip}:{$port}",
                        ];
                    }

                    return parent::checkConnection($ip, $port, $timeoutMs);
                }
            };

            return new SamsungTvDriver($transport, $this->statusService, false, false);
        }

        if ($device->category === PhysicalDevice::CATEGORY_SMART_RELAY && $device->brand === PhysicalDevice::BRAND_SHELLY) {
            $mappedScenario = match ($upperScenario) {
                'OFFLINE', 'DISCONNECTED' => FakeShellyHttpRpcTransport::SCENARIO_OFFLINE,
                'TIMEOUT' => FakeShellyHttpRpcTransport::SCENARIO_TIMEOUT,
                'AUTH_REQUIRED', 'AUTH_REJECTED', 'REJECTED', 'PAIRING_REQUIRED' => FakeShellyHttpRpcTransport::SCENARIO_AUTH_REQUIRED,
                'INVALID_CREDENTIALS', 'PAIRING_DENIED' => FakeShellyHttpRpcTransport::SCENARIO_INVALID_CREDENTIALS,
                'INVALID_JSON', 'MALFORMED' => FakeShellyHttpRpcTransport::SCENARIO_INVALID_JSON,
                default => FakeShellyHttpRpcTransport::SCENARIO_SUCCESS,
            };

            $transport = new FakeShellyHttpRpcTransport($mappedScenario);
            return new ShellyRelayDriver($transport, $this->statusService, false);
        }

        throw HardwareSimulationException::incompatibleDevice('SMART_TV atau SMART_RELAY', $device->category);
    }

    protected function createRealDriver(PhysicalDevice $device, bool $isRealVerified = false, bool $isAuthorizedPairing = false)
    {
        if ($device->category === PhysicalDevice::CATEGORY_SMART_TV && $device->brand === PhysicalDevice::BRAND_SAMSUNG) {
            $transport = app()->bound(SamsungTransportInterface::class)
                ? app(SamsungTransportInterface::class)
                : new SamsungWebSocketTransport();

            return new SamsungTvDriver($transport, $this->statusService, $isRealVerified, $isAuthorizedPairing);
        }

        if ($device->category === PhysicalDevice::CATEGORY_SMART_RELAY && $device->brand === PhysicalDevice::BRAND_SHELLY) {
            $transport = app()->bound(ShellyTransportInterface::class)
                ? app(ShellyTransportInterface::class)
                : new ShellyHttpRpcTransport();

            return new ShellyRelayDriver($transport, $this->statusService, $isRealVerified);
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
