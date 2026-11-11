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
    public const CMD_MUTE_TOGGLE = 'MUTE_TOGGLE';
    public const CMD_STANDBY = 'STANDBY';
    public const CMD_GET_STATUS = 'GET_STATUS';
    public const CMD_POWER_ON = 'POWER_ON'; // Unverified capability candidate
    public const CMD_VOLUME_UP = 'VOLUME_UP';
    public const CMD_VOLUME_DOWN = 'VOLUME_DOWN';
    public const CMD_SET_VOLUME = 'SET_VOLUME';
    public const CMD_GET_VOLUME = 'GET_VOLUME';

    public const SUPPORTED_COMMANDS = [
        self::CMD_MUTE,
        self::CMD_UNMUTE,
        self::CMD_MUTE_TOGGLE,
        self::CMD_STANDBY,
        self::CMD_GET_STATUS,
        self::CMD_VOLUME_UP,
        self::CMD_VOLUME_DOWN,
        self::CMD_SET_VOLUME, // Known but always returns UNSUPPORTED_CAPABILITY
        self::CMD_GET_VOLUME,
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

        $port = (int) ($options['port'] ?? config('hardware.default_ports.SAMSUNG_WSS', 8002));
        $timeoutMs = (int) ($options['timeout_ms'] ?? 20000);
        $appName = (string) ($options['app_name'] ?? config('hardware.app_name', 'Sugeng Rawuh POS'));

        // Re-use existing token by default; pass force_new=true to discard it and request fresh pairing.
        $forceNew = $options['force_new'] ?? false;
        $tokenToUse = $forceNew ? null : $device->pairing_payload;

        $response = $this->transport->openWebSocket($device->ip_address, $port, $appName, $tokenToUse, $timeoutMs);

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
        // No token received — do not set PAIRING_PAIRED without evidence of successful authentication.

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
                $modelName = $device->model ?: 'Samsung Smart TV';
                return HardwareCommandResult::createUnsupportedCapability(
                    $device->id,
                    self::DRIVER_TYPE,
                    self::CMD_POWER_ON,
                    "Perintah 'POWER_ON' belum diverifikasi untuk model TV ini ({$modelName} tidak mendukung aktivasi dari mode standby melalui WebSocket tanpa WOL fisik)."
                );
            }

            // Always dispatch Wake-on-LAN magic packet if MAC address is available
            if (!empty($device->mac_address)) {
                $this->sendWakeOnLan($device->mac_address, $device->ip_address);
            }

            // In real mode, send KEY_POWER via port 8002 (SSL WebSocket) + WOL.
            // PowerState REST API reports 'on' even in network standby on this model,
            // so we cannot use it to detect screen-off state. Always send the wake command.
            // Port 8002 is used because port 8001 rejects WebSocket upgrades on UA43FE60EFKXXD.
            if (!($this->transport instanceof \App\Services\Hardware\Transports\FakeSamsungWebSocketTransport)) {
                $port = (int) config('hardware.default_ports.SAMSUNG_WSS', 8002);
                $appName = config('hardware.app_name', 'Sugeng Rawuh POS');
                $wsResult = $this->transport->openWebSocket($device->ip_address, $port, $appName, $device->pairing_payload, 3000);
                if ($wsResult['success'] && !empty($wsResult['token']) && $wsResult['token'] !== $device->pairing_payload) {
                    $this->statusService->recordSuccessfulPairing($device, (string) $wsResult['token'], $this->isRealVerifiedMode);
                }
                $keyPowerSent = false;
                if ($wsResult['success']) {
                    $keyResult = $this->transport->sendKey('KEY_POWER');
                    $keyPowerSent = $keyResult['success'];
                }

                if ($keyPowerSent) {
                    // KEY_POWER is a toggle — do not mark ONLINE; physical state is unverifiable from protocol.
                    return HardwareCommandResult::createRealResult(
                        $device->id, self::DRIVER_TYPE, self::CMD_POWER_ON,
                        true, 'ACK_WAKEUP',
                        "Perintah KEY_POWER telah dikirim ke TV Samsung ({$device->model}). Layar TV akan menyala dalam beberapa detik.",
                        ['power_state' => 'waking', 'physical_state_verified' => false],
                        ['wol_sent' => !empty($device->mac_address), 'key_power_sent' => true]
                    );
                }

                // WebSocket failed — fall back to WOL only
                return HardwareCommandResult::createRealResult(
                    $device->id, self::DRIVER_TYPE, self::CMD_POWER_ON,
                    true, 'ACK_WAKEUP',
                    "Paket WOL telah dikirim ke TV Samsung ({$device->model}). Jika TV tidak menyala, tekan tombol power pada remote fisik TV.",
                    ['power_state' => 'waking', 'status' => 'WAKEUP_SENT'],
                    ['wol_sent' => !empty($device->mac_address), 'key_power_sent' => false, 'ws_status' => $wsResult['status'] ?? 'FAILED']
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

        // 3. Handle GET_STATUS and GET_VOLUME
        if ($upperCommand === self::CMD_GET_STATUS) {
            return $this->getStatus($device);
        }

        if ($upperCommand === self::CMD_GET_VOLUME) {
            return HardwareCommandResult::createRealResult(
                $device->id,
                self::DRIVER_TYPE,
                self::CMD_GET_VOLUME,
                true,
                'UNVERIFIED',
                'Status volume fisik tidak dilaporkan oleh transport WebSocket Samsung TV (Volume status: UNVERIFIED).',
                [
                    'volume' => null,
                    'volume_status' => 'UNVERIFIED',
                    'physical_state_verified' => false,
                ]
            );
        }

        // 4. SET_VOLUME is not supported — Samsung WebSocket protocol has no absolute volume setter.
        if ($upperCommand === self::CMD_SET_VOLUME) {
            return HardwareCommandResult::createUnsupportedCapability(
                (int) ($device->id ?? 0),
                self::DRIVER_TYPE,
                self::CMD_SET_VOLUME,
                "Pengaturan volume numerik (SET_VOLUME) tidak didukung oleh protokol WebSocket Samsung TV. Gunakan VOLUME_UP atau VOLUME_DOWN untuk kontrol relatif."
            );
        }

        // 5. Map remote key code
        $keyMap = [
            self::CMD_MUTE => 'KEY_MUTE',
            self::CMD_UNMUTE => 'KEY_MUTE',
            self::CMD_MUTE_TOGGLE => 'KEY_MUTE',
            self::CMD_STANDBY => 'KEY_POWER',
            self::CMD_POWER_ON => 'KEY_POWER',
            self::CMD_VOLUME_UP => 'KEY_VOLUP',
            self::CMD_VOLUME_DOWN => 'KEY_VOLDOWN',
        ];

        $remoteKey = $keyMap[$upperCommand] ?? 'KEY_VOLDOWN';

        // 5b. Idempotent check for STANDBY: If TV is already in standby, succeed immediately
        if ($upperCommand === self::CMD_STANDBY) {
            $wsPort = (int) config('hardware.default_ports.SAMSUNG_WS', 8001);
            $info = $this->transport->getDeviceInfo($device->ip_address, $wsPort, 1500);
            if ($info['success'] && strtolower($info['info']['PowerState'] ?? '') === 'standby') {
                return HardwareCommandResult::createRealResult(
                    $device->id,
                    self::DRIVER_TYPE,
                    self::CMD_STANDBY,
                    true,
                    'ACK_TRANSPORT',
                    "TV Samsung ({$device->model}) sudah dalam keadaan mati/standby.",
                    [
                        'command' => self::CMD_STANDBY,
                        'remote_key' => 'KEY_POWER',
                        'power_state' => 'standby',
                        'physical_state_verified' => true,
                    ]
                );
            }
        }

        // 6. Connect WebSocket if not connected
        if (!$this->transport->isConnected()) {
            $port = (int) config('hardware.default_ports.SAMSUNG_WSS', 8002);
            $timeoutMs = config('hardware.connect_timeout_ms', 3000);
            $appName = config('hardware.app_name', 'Sugeng Rawuh POS');
            $existingToken = $device->pairing_payload;

            $connectResult = $this->transport->openWebSocket($device->ip_address, $port, $appName, $existingToken, $timeoutMs);
            if ($connectResult['success'] && !empty($connectResult['token']) && $connectResult['token'] !== $device->pairing_payload) {
                $this->statusService->recordSuccessfulPairing($device, (string) $connectResult['token'], $this->isRealVerifiedMode);
            }
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
                    // Check if rejection was caused by TV being asleep in standby
                    $wsPort = (int) config('hardware.default_ports.SAMSUNG_WS', 8001);
                    $info = $this->transport->getDeviceInfo($device->ip_address, $wsPort, 1000);
                    $isStandby = $info['success'] && strtolower($info['info']['PowerState'] ?? '') === 'standby';

                    if ($isStandby) {
                        return HardwareCommandResult::createError(
                            $device->id,
                            self::DRIVER_TYPE,
                            $upperCommand,
                            "TV Samsung sedang dalam mode standby (layar mati). Nyalakan TV terlebih dahulu untuk menerima perintah {$upperCommand}.",
                            'DEVICE_STANDBY',
                            ['power_state' => 'standby'],
                            false
                        );
                    }

                    $this->statusService->recordPairingFailure($device, $this->isRealVerifiedMode);

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

        // 7. Transmit key frame(s)
        $isRelativeVolume = in_array($upperCommand, [self::CMD_VOLUME_UP, self::CMD_VOLUME_DOWN], true);
        $step = 1;
        if ($isRelativeVolume) {
            $maxStep = (int) config('hardware.tv_control.max_step_frames', 5);
            $requestedStep = isset($payload['step']) ? (int) $payload['step'] : (int) config('hardware.tv_control.volume_step', 5);
            $step = max(1, min($maxStep, $requestedStep));
        }

        $intervalMs = (int) config('hardware.tv_control.volume_frame_interval_ms', 150);
        $framesSent = 0;
        $lastResult = null;

        for ($i = 0; $i < $step; $i++) {
            $keyResult = $this->transport->sendKey($remoteKey);
            $lastResult = $keyResult;

            if (!$keyResult['success']) {
                break;
            }

            $framesSent++;

            if ($i < $step - 1 && $intervalMs > 0) {
                usleep($intervalMs * 1000);
            }
        }

        if ($framesSent === 0 && (!isset($lastResult['success']) || !$lastResult['success'])) {
            $keyResult = $lastResult ?? ['status' => 'TRANSMIT_FAILED', 'message' => 'Pengiriman frame kunci gagal'];
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
        $isMuteAction = in_array($upperCommand, [self::CMD_MUTE, self::CMD_UNMUTE, self::CMD_MUTE_TOGGLE], true);

        if ($isMuteAction) {
            $message = "Frame remote '{$upperCommand}' (KEY_MUTE toggle) terkirim (ACK_TRANSPORT). Status audio fisik TV tidak dapat dibaca dari transport dan belum terverifikasi (PHYSICAL_STATE_UNVERIFIED).";
            $telemetry = [
                'command' => $upperCommand,
                'remote_key' => $remoteKey,
                'audio_state' => 'UNKNOWN_TOGGLE',
                'is_idempotent' => false,
                'physical_state_verified' => false,
            ];
        } elseif ($isRelativeVolume) {
            $message = "Frame remote '{$upperCommand}' ({$remoteKey}) sebanyak {$framesSent} frame berhasil dikirim ke transport layer (ACK_TRANSPORT). Level volume fisik aktual tidak dapat dibaca dari protokol WebSocket.";
            $telemetry = [
                'command' => $upperCommand,
                'remote_key' => $remoteKey,
                'frames_sent' => $framesSent,
                'requested_step' => $step,
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
            $lastResult['raw_response'] ?? []
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

    /**
     * Broadcast Wake-on-LAN (WOL) magic packet to device MAC address.
     *
     * @param string $macAddress
     * @param string|null $ipAddress
     * @return bool
     */
    public function sendWakeOnLan(string $macAddress, ?string $ipAddress = null): bool
    {
        $cleanMac = str_replace([':', '-', '.'], '', $macAddress);
        if (strlen($cleanMac) !== 12 || !ctype_xdigit($cleanMac)) {
            return false;
        }

        $packet = str_repeat(chr(0xFF), 6) . str_repeat(pack('H*', $cleanMac), 16);

        $udpSent = false;
        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock) {
            @socket_set_option($sock, SOL_SOCKET, SO_BROADCAST, 1);
            @socket_sendto($sock, $packet, strlen($packet), 0, '255.255.255.255', 9);
            @socket_sendto($sock, $packet, strlen($packet), 0, '255.255.255.255', 7);

            if ($ipAddress) {
                $parts = explode('.', $ipAddress);
                if (count($parts) === 4) {
                    $subnetBcast = "{$parts[0]}.{$parts[1]}.{$parts[2]}.255";
                    @socket_sendto($sock, $packet, strlen($packet), 0, $subnetBcast, 9);
                    @socket_sendto($sock, $packet, strlen($packet), 0, $subnetBcast, 7);
                }
                @socket_sendto($sock, $packet, strlen($packet), 0, $ipAddress, 9);
            }

            @socket_close($sock);
            $udpSent = true;
        }

        $helperSent = $this->forwardWakeOnLanToHostHelper($macAddress, $ipAddress);

        return $udpSent || $helperSent;
    }

    /**
     * Forward Wake-on-LAN request to Windows Host WOL Helper when enabled (Docker Desktop NAT bridge).
     */
    protected function forwardWakeOnLanToHostHelper(string $macAddress, ?string $ipAddress = null): bool
    {
        if (!config('hardware.wol_helper.enabled', false)) {
            return false;
        }

        $url = trim((string) config('hardware.wol_helper.url', ''));
        if ($url === '') {
            return false;
        }

        $token = (string) config('hardware.wol_helper.token', '');
        $timeoutMs = max(200, (int) config('hardware.wol_helper.timeout_ms', 1500));

        $payload = json_encode([
            'mac_address' => $macAddress,
            'ip_address' => $ipAddress,
        ]);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        if ($token !== '') {
            $headers[] = 'X-WOL-Token: ' . $token;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $payload,
                'timeout' => $timeoutMs / 1000.0,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return false;
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) && !empty($decoded['success']);
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
