<?php

namespace App\Services\Hardware\Drivers;

use App\Exceptions\HardwareSafetyException;
use App\Exceptions\HardwareSimulationException;
use App\Models\PhysicalDevice;
use App\Services\Hardware\Contracts\HardwareDriverInterface;
use App\Services\Hardware\DeviceStatusManagementService;
use App\Services\Hardware\DTO\HardwareCommandResult;
use App\Services\Hardware\Security\HardwareSafetyGuard;
use App\Services\Hardware\Transports\SamsungTransportInterface;

class SamsungTvDriver implements HardwareDriverInterface
{
    public const DRIVER_TYPE = 'REAL_SAMSUNG_TV';

    public const CMD_MUTE = 'MUTE';
    public const CMD_UNMUTE = 'UNMUTE';
    public const CMD_STANDBY = 'STANDBY';
    public const CMD_GET_STATUS = 'GET_STATUS';
    public const CMD_POWER_ON = 'POWER_ON'; // Unverified capability candidate

    public const SUPPORTED_COMMANDS = [
        self::CMD_MUTE,
        self::CMD_UNMUTE,
        self::CMD_STANDBY,
        self::CMD_GET_STATUS,
    ];

    protected SamsungTransportInterface $transport;
    protected ?DeviceStatusManagementService $statusService;
    protected bool $isRealVerifiedMode = false;

    public function __construct(
        SamsungTransportInterface $transport,
        ?DeviceStatusManagementService $statusService = null,
        bool $isRealVerifiedMode = false
    ) {
        $this->transport = $transport;
        $this->statusService = $statusService ?? new DeviceStatusManagementService();
        $this->isRealVerifiedMode = $isRealVerifiedMode;
    }

    public function getDriverType(): string
    {
        return self::DRIVER_TYPE;
    }

    public function getSupportedCommands(): array
    {
        return self::SUPPORTED_COMMANDS;
    }

    public function getTransport(): SamsungTransportInterface
    {
        return $this->transport;
    }

    /**
     * Probe device reachability.
     */
    public function probe(PhysicalDevice $device): HardwareCommandResult
    {
        $this->validateDevice($device);

        $port = config('hardware.default_ports.SAMSUNG_WS', 8001);
        $timeoutMs = config('hardware.connect_timeout_ms', 3000);

        $response = $this->transport->checkConnection($device->ip_address, $port, $timeoutMs);

        if ($response['status'] === 'OFFLINE') {
            return HardwareCommandResult::createOffline(
                $device->id,
                self::DRIVER_TYPE,
                'PROBE',
                "Samsung TV unreachable on {$device->ip_address}:{$port} (Device Offline)"
            );
        }

        if ($response['status'] === 'TIMEOUT') {
            return HardwareCommandResult::createTimeout(
                $device->id,
                self::DRIVER_TYPE,
                'PROBE',
                $timeoutMs
            );
        }

        if (!$response['success']) {
            return HardwareCommandResult::createError(
                $device->id,
                self::DRIVER_TYPE,
                'PROBE',
                $response['message'] ?? 'Probe failed',
                $response['status'] ?? 'PROBE_FAILED',
                [],
                false
            );
        }

        // Only real verified mode mutates DB connection status
        $this->statusService->updateConnectionStatus($device, PhysicalDevice::CONNECTION_ONLINE, $this->isRealVerifiedMode);

        return HardwareCommandResult::createRealResult(
            $device->id,
            self::DRIVER_TYPE,
            'PROBE',
            true,
            'SUCCESS',
            $response['message'] ?? 'Probe sukses',
            ['ip' => $device->ip_address, 'status' => 'ONLINE'],
            $response['data'] ?? []
        );
    }

    /**
     * Pair device and obtain authorization token.
     */
    public function pair(PhysicalDevice $device, array $options = []): HardwareCommandResult
    {
        $this->validateDevice($device);

        $port = (int) ($options['port'] ?? config('hardware.default_ports.SAMSUNG_WS', 8001));
        $timeoutMs = (int) ($options['timeout_ms'] ?? config('hardware.timeout_ms', 3000));
        $appName = (string) ($options['app_name'] ?? config('hardware.app_name', 'Sugeng Rawuh POS'));

        // Existing encrypted pairing payload token (if already stored)
        $existingToken = $device->pairing_payload;

        $response = $this->transport->openWebSocket($device->ip_address, $port, $appName, $existingToken, $timeoutMs);

        if ($response['status'] === 'OFFLINE') {
            return HardwareCommandResult::createOffline(
                $device->id,
                self::DRIVER_TYPE,
                'PAIR',
                "Koneksi pairing gagal: Samsung TV di {$device->ip_address}:{$port} offline"
            );
        }

        if ($response['status'] === 'TIMEOUT') {
            return HardwareCommandResult::createTimeout(
                $device->id,
                self::DRIVER_TYPE,
                'PAIR',
                $timeoutMs
            );
        }

        if ($response['status'] === 'AUTH_REJECTED') {
            $this->statusService->recordPairingFailure($device, $this->isRealVerifiedMode);

            return HardwareCommandResult::createRejected(
                $device->id,
                self::DRIVER_TYPE,
                'PAIR',
                "Otorisasi pairing ditolak pada layar Samsung TV (ms.channel.unauthorized)"
            );
        }

        if (!$response['success']) {
            return HardwareCommandResult::createError(
                $device->id,
                self::DRIVER_TYPE,
                'PAIR',
                $response['message'] ?? 'Pairing gagal',
                $response['status'] ?? 'PAIRING_FAILED',
                [],
                false
            );
        }

        $token = $response['token'] ?? null;
        if (!empty($token)) {
            $this->statusService->recordSuccessfulPairing($device, $token, $this->isRealVerifiedMode);
        }

        return HardwareCommandResult::createRealResult(
            $device->id,
            self::DRIVER_TYPE,
            'PAIR',
            true,
            'PAIRED',
            'Pairing handshake dengan Samsung Smart TV berhasil diselesaikan',
            ['ip' => $device->ip_address, 'pairing_status' => 'PAIRED'],
            ['token_secured' => true, 'event' => $response['event'] ?? 'ms.channel.connect']
        );
    }

    /**
     * Send operational command to Samsung TV.
     */
    public function sendCommand(PhysicalDevice $device, string $command, array $payload = []): HardwareCommandResult
    {
        $this->validateDevice($device);

        $upperCommand = strtoupper(trim($command));

        // 1. Check for POWER_ON unverified capability
        if ($upperCommand === self::CMD_POWER_ON) {
            $verified = $device->verified_capabilities ?? [];
            if (!in_array(self::CMD_POWER_ON, array_map('strtoupper', $verified), true)) {
                return HardwareCommandResult::createUnsupportedCapability(
                    $device->id,
                    self::DRIVER_TYPE,
                    self::CMD_POWER_ON,
                    "Perintah 'POWER_ON' belum diverifikasi untuk model TV ini (Samsung 50U8500 tidak mendukung aktivasi dari mode standby melalui WebSocket tanpa WOL fisik)."
                );
            }
        }

        // 2. Validate supported commands
        if (!in_array($upperCommand, self::SUPPORTED_COMMANDS, true) && $upperCommand !== self::CMD_POWER_ON) {
            return HardwareCommandResult::createError(
                $device->id,
                self::DRIVER_TYPE,
                $command,
                "Perintah '{$command}' tidak didukung oleh Samsung Smart TV driver.",
                'INVALID_COMMAND',
                [],
                false
            );
        }

        // 3. Handle GET_STATUS
        if ($upperCommand === self::CMD_GET_STATUS) {
            return $this->getStatus($device);
        }

        // 4. Map remote key code
        $keyMap = [
            self::CMD_MUTE => 'KEY_MUTE',
            self::CMD_UNMUTE => 'KEY_MUTE',
            self::CMD_STANDBY => 'KEY_POWER',
            self::CMD_POWER_ON => 'KEY_POWER',
        ];

        $remoteKey = $keyMap[$upperCommand] ?? 'KEY_VOLDOWN';

        // 5. Connect WebSocket if not connected
        if (!$this->transport->isConnected()) {
            $port = config('hardware.default_ports.SAMSUNG_WS', 8001);
            $timeoutMs = config('hardware.connect_timeout_ms', 3000);
            $appName = config('hardware.app_name', 'Sugeng Rawuh POS');
            $existingToken = $device->pairing_payload;

            $connectResult = $this->transport->openWebSocket($device->ip_address, $port, $appName, $existingToken, $timeoutMs);
            if (!$connectResult['success']) {
                if ($connectResult['status'] === 'OFFLINE') {
                    return HardwareCommandResult::createOffline(
                        $device->id,
                        self::DRIVER_TYPE,
                        $upperCommand,
                        "Perangkat offline pada {$device->ip_address}:{$port}"
                    );
                }
                if ($connectResult['status'] === 'TIMEOUT') {
                    return HardwareCommandResult::createTimeout(
                        $device->id,
                        self::DRIVER_TYPE,
                        $upperCommand,
                        $timeoutMs
                    );
                }
                if ($connectResult['status'] === 'AUTH_REJECTED') {
                    return HardwareCommandResult::createAuthRequired(
                        $device->id,
                        self::DRIVER_TYPE,
                        $upperCommand,
                        "Autentikasi ditolak pada layar TV. Harap lakukan pairing ulang."
                    );
                }
                return HardwareCommandResult::createError(
                    $device->id,
                    self::DRIVER_TYPE,
                    $upperCommand,
                    $connectResult['message'] ?? 'Koneksi WebSocket gagal',
                    $connectResult['status'] ?? 'CONNECTION_FAILED',
                    [],
                    false
                );
            }
        }

        // 6. Transmit key frame
        $keyResult = $this->transport->sendKey($remoteKey);

        if (!$keyResult['success']) {
            if ($keyResult['status'] === 'OFFLINE' || $keyResult['status'] === 'DISCONNECTED') {
                return HardwareCommandResult::createOffline(
                    $device->id,
                    self::DRIVER_TYPE,
                    $upperCommand,
                    "Koneksi terputus saat mengirim perintah {$upperCommand}"
                );
            }
            if ($keyResult['status'] === 'TIMEOUT') {
                return HardwareCommandResult::createTimeout(
                    $device->id,
                    self::DRIVER_TYPE,
                    $upperCommand,
                    config('hardware.timeout_ms', 3000)
                );
            }

            return HardwareCommandResult::createError(
                $device->id,
                self::DRIVER_TYPE,
                $upperCommand,
                $keyResult['message'] ?? 'Pengiriman frame kunci gagal',
                $keyResult['status'] ?? 'TRANSMIT_FAILED',
                [],
                false
            );
        }

        /*
         * SAMSUNG PROTOCOL AUDIT & MUTE SEMANTICS:
         * 1. 'KEY_MUTE' is a toggle key on physical Samsung remotes, NOT an idempotent setter.
         *    Repeated MUTE calls will toggle between muted and unmuted audio states.
         * 2. Samsung REST API (/api/v2/) does not provide audio/mute telemetry in PowerState.
         * 3. Transport acknowledgement (ACK_TRANSPORT) confirms WebSocket frame delivery,
         *    NOT physical audio state verification.
         * 4. The driver NEVER marks local database state as MUTED/UNMUTED.
         * 5. Automation workflows in G5 must NOT rely on deterministic mute state until verified.
         */
        $isMuteAction = in_array($upperCommand, [self::CMD_MUTE, self::CMD_UNMUTE], true);

        if ($isMuteAction) {
            $message = "Frame remote '{$upperCommand}' (KEY_MUTE toggle) terkirim (ACK_TRANSPORT). Status audio fisik TV tidak dapat dibaca dari transport dan belum terverifikasi (PHYSICAL_STATE_UNVERIFIED).";
            $telemetry = [
                'command' => $upperCommand,
                'remote_key' => $remoteKey,
                'audio_state' => 'UNKNOWN_TOGGLE',
                'is_idempotent' => false,
                'physical_state_verified' => false,
            ];
        } else {
            $message = "Frame remote '{$upperCommand}' terkirim dan diakui oleh transport layer (ACK_TRANSPORT). Kondisi fisik layar TV belum diverifikasi.";
            $telemetry = [
                'command' => $upperCommand,
                'remote_key' => $remoteKey,
                'physical_state_verified' => false,
            ];
        }

        return HardwareCommandResult::createRealResult(
            $device->id,
            self::DRIVER_TYPE,
            $upperCommand,
            true,
            'ACK_TRANSPORT',
            $message,
            $telemetry,
            $keyResult['raw_response'] ?? []
        );
    }

    /**
     * Retrieve status of Samsung TV.
     */
    public function getStatus(PhysicalDevice $device): HardwareCommandResult
    {
        $this->validateDevice($device);

        $port = config('hardware.default_ports.SAMSUNG_WS', 8001);
        $timeoutMs = config('hardware.timeout_ms', 3000);

        $info = $this->transport->getDeviceInfo($device->ip_address, $port, $timeoutMs);

        if (!$info['success']) {
            if ($info['status'] === 'OFFLINE') {
                return HardwareCommandResult::createOffline(
                    $device->id,
                    self::DRIVER_TYPE,
                    self::CMD_GET_STATUS,
                    "Samsung TV offline pada {$device->ip_address}:{$port}"
                );
            }

            return HardwareCommandResult::createError(
                $device->id,
                self::DRIVER_TYPE,
                self::CMD_GET_STATUS,
                $info['message'] ?? 'Gagal mengambil status perangkat',
                $info['status'] ?? 'STATUS_FAILED',
                [],
                false
            );
        }

        $deviceInfo = $info['info'] ?? [];
        $powerState = $deviceInfo['PowerState'] ?? 'UNKNOWN';

        return HardwareCommandResult::createRealResult(
            $device->id,
            self::DRIVER_TYPE,
            self::CMD_GET_STATUS,
            true,
            'SUCCESS',
            "Status Samsung TV berhasil diambil (PowerState: {$powerState})",
            ['power_state' => $powerState, 'model' => $deviceInfo['modelName'] ?? $device->model],
            $deviceInfo
        );
    }

    protected function validateDevice(PhysicalDevice $device): void
    {
        if ($device->category !== PhysicalDevice::CATEGORY_SMART_TV || $device->brand !== PhysicalDevice::BRAND_SAMSUNG) {
            throw HardwareSimulationException::incompatibleDevice(
                'SMART_TV (SAMSUNG)',
                "{$device->category} ({$device->brand})"
            );
        }

        if (!$device->is_active) {
            throw HardwareSimulationException::deviceInactive($device->name);
        }
    }
}
