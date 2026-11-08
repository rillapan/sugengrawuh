<?php

namespace App\Http\Controllers;

use App\Exceptions\TvControlException;
use App\Models\Device;
use App\Models\TvAlertEvent;
use App\Services\Hardware\TvAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TvAgentController extends Controller
{
    protected TvAlertService $tvAlertService;

    public function __construct(TvAlertService $tvAlertService)
    {
        $this->tvAlertService = $tvAlertService;
    }

    /**
     * Poll pending TV control events for a specific PlayStation station.
     * Endpoint: GET /api/tv-agent/events
     */
    public function pollEvents(Request $request): JsonResponse
    {
        $rawStationId = $request->query('station_id', $request->header('X-TV-Station-Id'));
        if ($rawStationId === null || trim((string) $rawStationId) === '' || !is_numeric($rawStationId)) {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'error_code' => 'MISSING_STATION_ID',
                'message' => 'Parameter station_id wajib disertakan.',
            ], 401);
        }

        $stationId = (int) $rawStationId;
        $station = Device::find($stationId);
        if (!$station) {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'error_code' => 'STATION_NOT_FOUND',
                'message' => 'Station tidak ditemukan.',
            ], 404);
        }

        $token = $this->extractConsumerToken($request);
        if ($token === null || $token === '') {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'error_code' => 'UNAUTHORIZED_CONSUMER',
                'message' => 'Token autentikasi TV consumer wajib disertakan.',
            ], 401);
        }

        if (!$this->tvAlertService->verifyConsumerToken((int) $station->id, $token)) {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'error_code' => 'UNAUTHORIZED_CONSUMER',
                'message' => 'Token autentikasi TV consumer tidak valid untuk station ini.',
            ], 403);
        }

        // Safely evaluate 10-minute remaining threshold & sweep stale/expired events
        try {
            $this->tvAlertService->evaluateAutoAlerts();
        } catch (\Throwable $e) {
            // Non-blocking fallback
        }

        $pollResult = $this->tvAlertService->pollEventsForConsumer($station);
        $tvState = $pollResult['tv_state'] ?? [];

        $formattedEvents = collect($pollResult['events'])->map(function (TvAlertEvent $ev) {
            return [
                'id' => $ev->id,
                'event_id' => $ev->id,
                'station_id' => (int) $ev->station_id,
                'station_name' => $ev->station_name,
                'transaction_id' => $ev->transaction_id,
                'session_generation' => (int) $ev->session_generation,
                'event_type' => $ev->event_type,
                'command' => $ev->command,
                'message_preset' => $ev->message_preset,
                'message_title' => $ev->message_title,
                'message_body' => $ev->message_body,
                'message_text' => $ev->message_body,
                'display_duration_seconds' => (int) $ev->display_duration_seconds,
                'duration_seconds' => (int) $ev->display_duration_seconds,
                'simulated_volume' => (int) $ev->simulated_volume,
                'simulated_muted' => (bool) $ev->simulated_muted,
                'control_mode' => $ev->control_mode,
                'status' => $ev->status,
                'result_code' => $ev->result_code,
                'created_at' => $ev->created_at ? $ev->created_at->toIso8601String() : null,
            ];
        })->values()->all();

        return response()->json([
            'success' => true,
            'station' => [
                'id' => (int) $station->id,
                'name' => $station->nama,
                'status' => $pollResult['station_status'],
                'session_generation' => (int) $pollResult['session_generation'],
            ],
            'simulated_state' => [
                'volume' => (int) ($tvState['volume'] ?? 30),
                'mute' => (bool) ($tvState['mute'] ?? false),
                'power' => (string) ($tvState['power'] ?? 'ON'),
                'simulated' => true,
            ],
            'events' => $formattedEvents,
            'server_time' => $pollResult['polled_at'] ?? now()->toIso8601String(),
        ]);
    }

    /**
     * Acknowledge an alert/control event from the consumer app (Tizen Web App / TV Lab Simulator)
     * after rendering the overlay/volume state on screen.
     * Endpoint: POST /api/tv-agent/events/{event}/ack
     */
    public function acknowledge(int $event, Request $request): JsonResponse
    {
        $eventModel = TvAlertEvent::find($event);
        if (!$eventModel) {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'error_code' => 'EVENT_NOT_FOUND',
                'message' => 'Event peringatan TV tidak ditemukan.',
            ], 404);
        }

        $token = $this->extractConsumerToken($request);
        if ($token === null || $token === '') {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'error_code' => 'UNAUTHORIZED_CONSUMER',
                'message' => 'Token autentikasi TV consumer wajib disertakan.',
            ], 401);
        }

        $rawStationId = $request->input('station_id', $request->query('station_id', $request->header('X-TV-Station-Id')));
        $targetStationId = ($rawStationId !== null && trim((string) $rawStationId) !== '' && is_numeric($rawStationId))
            ? (int) $rawStationId
            : (int) $eventModel->station_id;

        $consumerStation = Device::find($targetStationId);
        if (!$consumerStation) {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'error_code' => 'STATION_NOT_FOUND',
                'message' => 'Station consumer tidak ditemukan.',
            ], 404);
        }

        if (!$this->tvAlertService->verifyConsumerToken((int) $consumerStation->id, $token)) {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'error_code' => 'UNAUTHORIZED_CONSUMER',
                'message' => 'Token autentikasi TV consumer tidak valid untuk station ini.',
            ], 403);
        }

        try {
            $ackedEvent = $this->tvAlertService->acknowledgeEvent($eventModel, $consumerStation, $request->all());

            return response()->json([
                'success' => true,
                'status' => 'success',
                'message' => 'ACK diterima. Status event diperbarui menjadi DELIVERED_TO_APP (Simulasi — bukan konfirmasi layar fisik Samsung).',
                'data' => [
                    'event_id' => $ackedEvent->id,
                    'id' => $ackedEvent->id,
                    'station_id' => (int) $ackedEvent->station_id,
                    'status' => $ackedEvent->status,
                    'result_code' => $ackedEvent->result_code,
                    'acked_at' => $ackedEvent->acked_at ? $ackedEvent->acked_at->toIso8601String() : now()->toIso8601String(),
                    'physical_display_verified' => false,
                ],
            ], 200);
        } catch (TvControlException $e) {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'error_code' => $e->getErrorCode(),
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }
    }

    /**
     * Extract consumer token from header, Bearer token, or query/body param.
     */
    protected function extractConsumerToken(Request $request): ?string
    {
        $headerToken = $request->header('X-TV-Device-Token');
        if ($headerToken !== null && trim($headerToken) !== '') {
            return trim($headerToken);
        }

        $bearer = $request->bearerToken();
        if ($bearer !== null && trim($bearer) !== '') {
            return trim($bearer);
        }

        $paramToken = $request->input('device_token', $request->input('token', $request->query('token', $request->query('device_token'))));
        if ($paramToken !== null && trim((string) $paramToken) !== '') {
            return trim((string) $paramToken);
        }

        return null;
    }
}
