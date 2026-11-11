<?php

namespace App\Http\Controllers\Api;

use App\Console\Commands\BillingDaemonCommand;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HealthCheckController extends Controller
{
    /**
     * Lightweight health & readiness endpoint for Docker / Windows Host watchdog.
     */
    public function index(): JsonResponse
    {
        $dbConnected = false;
        $schemaReady = false;

        try {
            DB::connection()->getPdo();
            $dbConnected = true;
            $schemaReady = Schema::hasTable('users') && Schema::hasTable('transactions');
        } catch (\Throwable $e) {
            $dbConnected = false;
            $schemaReady = false;
        }

        $daemonHeartbeat = null;
        try {
            $daemonHeartbeat = Cache::get(BillingDaemonCommand::HEARTBEAT_CACHE_KEY);
        } catch (\Throwable $e) {
            $daemonHeartbeat = null;
        }

        $status = $dbConnected ? 'ok' : 'degraded';
        $httpStatus = $dbConnected ? 200 : 503;

        return response()->json([
            'status' => $status,
            'app' => 'Sugeng Rawuh Billing & POS',
            'environment' => app()->environment(),
            'database' => $dbConnected ? 'connected' : 'unreachable',
            'schema_ready' => $schemaReady,
            'daemon_heartbeat' => $daemonHeartbeat,
            'hardware' => [
                'driver' => (string) config('hardware.driver', 'none'),
                'real_execution_enabled' => (bool) config('hardware.real_execution_enabled', false),
                'console_real_execution_enabled' => (bool) config('hardware.console_real_execution_enabled', false),
                'automation_enabled' => (bool) config('hardware.automation_enabled', false),
            ],
            'server_time' => Carbon::now()->toIso8601String(),
        ], $httpStatus);
    }
}

