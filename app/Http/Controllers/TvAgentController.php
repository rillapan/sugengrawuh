<?php

namespace App\Http\Controllers;

use App\Exceptions\TvControlException;
use App\Models\Device;
use App\Models\TvAlertEvent;
use App\Services\Hardware\TvAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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
            return $ev->toConsumerPayload();
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
            'volume_limit_config' => $pollResult['volume_limit_config'] ?? [
                'enabled' => false,
                'max_limit' => 50,
                'current_volume' => null,
                'is_muted' => false,
                'enforcement_status' => 'WITHIN_LIMIT',
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

            $physicalDisplayVerified = ($ackedEvent->execution_status === TvAlertEvent::STATUS_STATE_VERIFIED)
                || ($request->input('physical_display_verified') === true);

            return response()->json([
                'success' => true,
                'status' => 'success',
                'message' => 'ACK diterima. Status event diperbarui menjadi DELIVERED_TO_APP (Simulasi — bukan konfirmasi layar fisik Samsung).',
                'data' => [
                    'event_id' => $ackedEvent->id,
                    'id' => $ackedEvent->id,
                    'station_id' => (int) $ackedEvent->station_id,
                    'status' => $ackedEvent->status,
                    'execution_status' => $ackedEvent->execution_status,
                    'result_code' => $ackedEvent->result_code,
                    'real_volume' => $ackedEvent->real_volume,
                    'real_muted' => $ackedEvent->real_muted,
                    'hardware_error_code' => $ackedEvent->hardware_error_code,
                    'hardware_error_message' => $ackedEvent->hardware_error_message,
                    'acked_at' => $ackedEvent->acked_at ? $ackedEvent->acked_at->toIso8601String() : now()->toIso8601String(),
                    'physical_display_verified' => $physicalDisplayVerified,
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
     * Record a volume enforcement event reported by the consumer TV agent (Tizen / Simulator).
     * Endpoint: POST /api/tv-agent/events/volume-enforcement
     */
    public function recordVolumeEnforcement(Request $request): JsonResponse
    {
        $rawStationId = $request->input('station_id', $request->query('station_id', $request->header('X-TV-Station-Id')));
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

        $validator = Validator::make($request->all(), [
            'previous_volume' => 'required|integer|min:0|max:100',
            'enforced_volume' => 'required|integer|min:0|max:100',
            'max_limit' => 'required|integer|min:0|max:100',
            'enforcement_source' => 'nullable|string|in:TIZEN_AGENT,ADMIN_ENFORCE,SIMULATOR',
            'status' => 'nullable|string|in:ENFORCED,FAILED,UNSUPPORTED',
            'details' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'error_code' => 'VALIDATION_ERROR',
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $log = $this->tvAlertService->recordVolumeEnforcement($station, $validator->validated());

            return response()->json([
                'success' => true,
                'status' => 'success',
                'message' => 'Log penegakan batas volume berhasil dicatat.',
                'data' => [
                    'enforcement_id' => $log->id,
                    'id' => $log->id,
                    'station_id' => (int) $log->station_id,
                    'physical_device_id' => (int) $log->physical_device_id,
                    'previous_volume' => (int) $log->previous_volume,
                    'enforced_volume' => (int) $log->enforced_volume,
                    'max_limit' => (int) $log->max_limit,
                    'enforcement_source' => $log->enforcement_source,
                    'status' => $log->status,
                    'details' => $log->details,
                    'created_at' => $log->created_at ? $log->created_at->toIso8601String() : now()->toIso8601String(),
                ],
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'error_code' => 'ENFORCEMENT_RECORD_FAILED',
                'message' => $e->getMessage(),
            ], 500);
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
