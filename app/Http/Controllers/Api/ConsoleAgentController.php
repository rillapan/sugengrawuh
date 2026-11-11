<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PhysicalDevice;
use App\Models\TvAlertEvent;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ConsoleAgentController extends Controller
{
    /**
     * Authenticate physical device using bearer token or X-Device-Token header.
     *
     * @param Request $request
     * @return PhysicalDevice
     * @throws \Illuminate\Auth\AuthenticationException
     */
    protected function authenticateDevice(Request $request): PhysicalDevice
    {
        $rawToken = $request->bearerToken();
        if (empty($rawToken)) {
            $rawToken = $request->header('X-Device-Token');
        }

        if (empty($rawToken)) {
            $rawToken = $request->query('token');
        }

        if (empty($rawToken)) {
            abort(401, 'Device authentication token required (Bearer or X-Device-Token).');
        }

        $device = PhysicalDevice::findByToken((string) $rawToken);
        if (!$device) {
            abort(401, 'Token autentikasi perangkat tidak valid.');
        }

        if (!$device->is_active) {
            abort(403, "Perangkat '{$device->name}' berstatus nonaktif.");
        }

        if ($device->category !== PhysicalDevice::CATEGORY_GAME_CONSOLE) {
            abort(403, "Perangkat '{$device->name}' bukan merupakan Game Console.");
        }

        return $device;
    }

    /**
     * Poll pending notification events for authenticated console.
     * Endpoint: GET /api/console-agent/v1/events
     */
    public function pollEvents(Request $request): JsonResponse
    {
        $device = $this->authenticateDevice($request);

        // Update heartbeat passively on poll
        $device->update([
            'last_seen_at' => Carbon::now(),
            'connection_status' => PhysicalDevice::CONNECTION_ONLINE,
        ]);

        $station = $device->current_station;
        $stationId = $station ? $station->id : (int) $request->query('station_id', 0);

        $query = TvAlertEvent::where('status', TvAlertEvent::STATUS_PENDING);

        if ($stationId > 0) {
            $query->where(function ($q) use ($device, $stationId) {
                $q->where('physical_device_id', $device->id)
                  ->orWhere(function ($sq) use ($stationId) {
                      $sq->where('station_id', $stationId)
                         ->whereIn('target_channel', ['CONSOLE', 'BOTH', 'AUTO']);
                  });
            });
        } else {
            $query->where('physical_device_id', $device->id);
        }

        $pendingEvent = $query->orderBy('id', 'asc')->first();

        if (!$pendingEvent) {
            return response()->json([
                'success' => true,
                'has_events' => false,
                'events' => [],
                'count' => 0,
            ], 200);
        }

        // Check session validity / expiry
        $now = Carbon::now();
        if ($station && (int) $pendingEvent->session_generation !== (int) ($station->session_generation ?: 1)) {
            $pendingEvent->update([
                'status' => TvAlertEvent::STATUS_STALE,
                'error_message' => 'Session generation mismatch upon console dispatch',
                'cancelled_at' => $now,
            ]);

            return response()->json([
                'success' => true,
                'has_events' => false,
                'events' => [],
                'count' => 0,
            ], 200);
        }

        // Mark as dispatched / delivered
        $pendingEvent->update([
            'status' => TvAlertEvent::STATUS_DELIVERED,
            'delivered_at' => $now,
            'physical_device_id' => $device->id,
        ]);

        $eventPayload = [
            'id' => $pendingEvent->id,
            'event_id' => $pendingEvent->id,
            'station_id' => $pendingEvent->station_id,
            'station_name' => $pendingEvent->station_name,
            'transaction_id' => $pendingEvent->transaction_id,
            'event_type' => $pendingEvent->event_type,
            'command' => $pendingEvent->command,
            'preset' => $pendingEvent->message_preset,
            'title' => $pendingEvent->message_title,
            'message' => $pendingEvent->message_body,
            'duration_seconds' => (int) ($pendingEvent->display_duration_seconds ?: 8),
            'target_channel' => $pendingEvent->target_channel,
            'created_at' => $pendingEvent->created_at ? $pendingEvent->created_at->toIso8601String() : $now->toIso8601String(),
            'ack_url' => url("/api/console-agent/v1/events/{$pendingEvent->id}/ack"),
        ];

        Log::info('[CONSOLE_AGENT] Pending event dispatched to PS4 agent', [
            'device_id' => $device->id,
            'event_id' => $pendingEvent->id,
            'title' => $pendingEvent->message_title,
        ]);

        return response()->json([
            'success' => true,
            'has_events' => true,
            'count' => 1,
            'events' => [$eventPayload],
            'event' => $eventPayload,
        ], 200);
    }

    /**
     * Acknowledge execution of an alert by console agent.
     * Endpoint: POST /api/console-agent/v1/events/{event}/ack
     */
    public function acknowledge(int $eventId, Request $request): JsonResponse
    {
        $device = $this->authenticateDevice($request);

        $event = TvAlertEvent::find($eventId);
        if (!$event) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found.',
            ], 404);
        }

        $now = Carbon::now();
        $statusInput = strtoupper(trim((string) $request->input('status', 'NOTIFICATION_API_CALLED')));
        $errorMsg = $request->input('error_message');
        $rawPayload = json_encode($request->all());

        if (in_array($statusInput, ['NOTIFICATION_API_CALLED', 'AGENT_RECEIVED', 'SUCCESS', 'ACKED'], true)) {
            $event->update([
                'status' => TvAlertEvent::STATUS_COMPLETED,
                'execution_status' => TvAlertEvent::STATUS_STATE_VERIFIED,
                'result_code' => 'SUCCESS',
                'acked_at' => $now,
                'agent_response_payload' => $rawPayload,
            ]);

            Log::info('[CONSOLE_AGENT] Event successfully acknowledged by PS4', [
                'device_id' => $device->id,
                'event_id' => $event->id,
                'status' => $statusInput,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Event acknowledged successfully.',
                'event_id' => $event->id,
                'status' => $event->status,
                'execution_status' => $event->execution_status,
                'acked_at' => $now->toIso8601String(),
            ], 200);
        }

        // Failure reporting
        $event->update([
            'status' => TvAlertEvent::STATUS_FAILED,
            'execution_status' => 'FAILED',
            'result_code' => 'AGENT_EXECUTION_FAILED',
            'error_message' => $errorMsg ?: 'Agent reported execution failure',
            'agent_response_payload' => $rawPayload,
            'acked_at' => $now,
        ]);

        Log::warning('[CONSOLE_AGENT] Agent reported notification failure', [
            'device_id' => $device->id,
            'event_id' => $event->id,
            'error' => $errorMsg,
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failure recorded.',
            'event_id' => $event->id,
            'status' => $event->status,
            'error_message' => $event->error_message,
        ], 200);
    }

    /**
     * Heartbeat report from console agent.
     * Endpoint: POST /api/console-agent/v1/heartbeat
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $device = $this->authenticateDevice($request);
        $now = Carbon::now();

        $updateData = [
            'last_seen_at' => $now,
            'connection_status' => PhysicalDevice::CONNECTION_ONLINE,
        ];

        if ($request->filled('firmware')) {
            $updateData['firmware_version'] = (string) $request->input('firmware');
        }

        if ($request->filled('homebrew_version') || $request->filled('plugin_version')) {
            $updateData['homebrew_version'] = (string) ($request->input('homebrew_version') ?: $request->input('plugin_version'));
        }

        if ($request->filled('homebrew_type')) {
            $updateData['homebrew_type'] = (string) $request->input('homebrew_type');
        }

        $device->update($updateData);

        return response()->json([
            'success' => true,
            'status' => 'ONLINE',
            'device_id' => $device->id,
            'device_name' => $device->name,
            'timestamp' => $now->toIso8601String(),
            'message' => 'Heartbeat received successfully.',
        ], 200);
    }
}

