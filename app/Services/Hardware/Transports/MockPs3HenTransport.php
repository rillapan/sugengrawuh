<?php

namespace App\Services\Hardware\Transports;

class MockPs3HenTransport implements Ps3TransportInterface
{
    protected string $forcedScenario = 'SUCCESS';
    protected array $sentNotifications = [];

    public function setScenario(string $scenario): self
    {
        $this->forcedScenario = strtoupper($scenario);
        return $this;
    }

    public function checkConnection(string $ip, int $port, int $timeoutMs): array
    {
        if ($this->forcedScenario === 'SECURITY_REJECTED') {
            return [
                'success' => false,
                'status' => 'SECURITY_REJECTED',
                'message' => "Target IP ditolak oleh aturan keamanan hardware (SSRF Protection)",
                'data' => [],
            ];
        }

        if ($this->forcedScenario === 'OFFLINE') {
            return [
                'success' => false,
                'status' => 'OFFLINE',
                'message' => "PS3 HEN unreachable on {$ip}:{$port}",
                'data' => [],
            ];
        }

        if ($this->forcedScenario === 'TIMEOUT') {
            return [
                'success' => false,
                'status' => 'TIMEOUT',
                'message' => "Connection to PS3 HEN timed out after {$timeoutMs}ms",
                'data' => [],
            ];
        }

        return [
            'success' => true,
            'status' => 'ONLINE',
            'message' => 'webMAN MOD HTTP server active (Simulated)',
            'data' => [
                'ip' => $ip,
                'port' => $port,
                'version' => 'webMAN MOD 1.47.45 (Simulated)',
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

        if ($this->forcedScenario === 'SECURITY_REJECTED') {
            return [
                'success' => false,
                'status' => 'SECURITY_REJECTED',
                'message' => "Target IP ditolak oleh aturan keamanan hardware (SSRF Protection)",
                'raw_response' => null,
            ];
        }

        if ($this->forcedScenario === 'OFFLINE') {
            return [
                'success' => false,
                'status' => 'OFFLINE',
                'message' => "Gagal mengirim notifikasi: PS3 HEN offline pada {$ip}:{$port}",
                'raw_response' => null,
            ];
        }

        if ($this->forcedScenario === 'TIMEOUT') {
            return [
                'success' => false,
                'status' => 'TIMEOUT',
                'message' => "Pengiriman notifikasi ke PS3 HEN timeout setelah {$timeoutMs}ms",
                'raw_response' => null,
            ];
        }

        if ($this->forcedScenario === 'ERROR') {
            return [
                'success' => false,
                'status' => 'HTTP_ERROR',
                'message' => 'Simulated HTTP 500 from webMAN MOD',
                'raw_response' => 'Internal error',
            ];
        }

        return [
            'success' => true,
            'status' => 'ACK_HTTP',
            'message' => 'Notifikasi webMAN MOD berhasil diterima dan diproses oleh konsol (Simulasi)',
            'raw_response' => 'HTTP/1.1 200 OK - webMAN MOD popup dispatched',
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

