<?php

namespace App\Services\Hardware\Transports;

class MockPs4HenTransport implements Ps4TransportInterface
{
    protected string $forcedScenario = 'SUCCESS';
    protected array $sentNotifications = [];
    protected bool $protocolVerified = true;

    public function setScenario(string $scenario): self
    {
        $this->forcedScenario = strtoupper($scenario);
        return $this;
    }

    public function setProtocolVerified(bool $verified): self
    {
        $this->protocolVerified = $verified;
        return $this;
    }

    public function isProtocolVerified(): bool
    {
        return $this->protocolVerified;
    }

    public function checkConnection(string $ip, int $port, int $timeoutMs): array
    {
        if (!$this->protocolVerified) {
            return [
                'success' => false,
                'status' => 'UNSUPPORTED_PROTOCOL',
                'message' => 'Protokol notifikasi PS4 belum dikonfigurasi / belum diverifikasi',
                'data' => [],
            ];
        }

        if ($this->forcedScenario === 'OFFLINE') {
            return [
                'success' => false,
                'status' => 'OFFLINE',
                'message' => "PS4 HEN unreachable on {$ip}:{$port}",
                'data' => [],
            ];
        }

        if ($this->forcedScenario === 'TIMEOUT') {
            return [
                'success' => false,
                'status' => 'TIMEOUT',
                'message' => "Connection to PS4 HEN timed out after {$timeoutMs}ms",
                'data' => [],
            ];
        }

        return [
            'success' => true,
            'status' => 'ONLINE',
            'message' => 'PS4 GoldHEN Notification daemon active (Simulated)',
            'data' => [
                'ip' => $ip,
                'port' => $port,
                'version' => 'GoldHEN 2.4b16 (Simulated)',
            ],
        ];
    }

    public function sendNotification(string $ip, int $port, string $message, int $timeoutMs): array
    {
        $this->sentNotifications[] = [
            'ip' => $ip,
            'port' => $port,
            'message' => $message,
            'timeout_ms' => $timeoutMs,
            'timestamp' => now()->toIso8601String(),
        ];

        if (!$this->protocolVerified) {
            return [
                'success' => false,
                'status' => 'UNSUPPORTED_PROTOCOL',
                'message' => 'Protokol notifikasi fisik PS4 HEN belum diverifikasi pada firmware ini (UNSUPPORTED_PROTOCOL)',
                'raw_response' => null,
            ];
        }

        if ($this->forcedScenario === 'OFFLINE') {
            return [
                'success' => false,
                'status' => 'OFFLINE',
                'message' => "Gagal mengirim notifikasi: PS4 HEN offline pada {$ip}:{$port}",
                'raw_response' => null,
            ];
        }

        if ($this->forcedScenario === 'TIMEOUT') {
            return [
                'success' => false,
                'status' => 'TIMEOUT',
                'message' => "Pengiriman notifikasi ke PS4 HEN timeout setelah {$timeoutMs}ms",
                'raw_response' => null,
            ];
        }

        return [
            'success' => true,
            'status' => 'ACK_HTTP',
            'message' => 'Notifikasi GoldHEN berhasil diterima oleh konsol PS4 (Simulasi)',
            'raw_response' => '{"status":"ok","message":"notification_displayed"}',
        ];
    }

    public function getSentNotifications(): array
    {
        return $this->sentNotifications;
    }

    public function clearSentNotifications(): void
    {
        $this->sentNotifications = [];
    }
}

