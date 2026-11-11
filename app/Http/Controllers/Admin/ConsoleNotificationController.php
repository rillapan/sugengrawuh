<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\TvControlException;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\TvAlertEvent;
use App\Services\Hardware\ConsoleNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ConsoleNotificationController extends Controller
{
    protected ConsoleNotificationService $service;

    public function __construct(ConsoleNotificationService $service)
    {
        $this->service = $service;
    }

    /**
     * Send manual notification to a station's Game Console.
     * Endpoint: POST /admin/stations/{station}/console-notifications
     */
    public function sendNotification(int $stationId, Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || (!$user->isAdmin() && !$user->isOwner())) {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'error_code' => 'FORBIDDEN',
                'message' => 'Hanya Admin atau Owner yang memiliki akses untuk mengirim notifikasi konsol.',
            ], 403);
        }

        $station = Device::with(['playstations', 'playstation'])->find($stationId);
        if (!$station) {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'error_code' => 'STATION_NOT_FOUND',
                'message' => 'Perangkat/stasiun tidak ditemukan.',
            ], 404);
        }

        $presetValue = $request->input('preset', $request->input('message_preset'));
        if ($presetValue !== null) {
            $normalizedPreset = strtoupper(trim((string) $presetValue));
            if ($normalizedPreset === '10_MIN_LEFT') {
                $normalizedPreset = ConsoleNotificationService::PRESET_REMAINING_10_MIN;
            }
            $request->merge(['preset' => $normalizedPreset]);
        }

        $validator = Validator::make($request->all(), [
            'preset' => 'required|string|in:REMAINING_10_MIN,TIME_REMAINING_10_MINUTES,ALMOST_FINISHED,TIME_EXPIRED,LOWER_VOLUME,CUSTOM',
            'custom_message' => 'nullable|required_if:preset,CUSTOM|string|max:120',
            'custom_title' => 'nullable|string|max:40',
            'duration_seconds' => 'nullable|integer|min:5|max:15',
        ], [
            'preset.required' => 'Preset notifikasi wajib dipilih.',
            'preset.in' => 'Preset notifikasi tidak valid.',
            'custom_message.required_if' => 'Pesan khusus wajib diisi jika memilih preset CUSTOM.',
            'custom_message.max' => 'Pesan khusus maksimal 120 karakter untuk Game Console.',
            'duration_seconds.min' => 'Durasi minimal 5 detik.',
            'duration_seconds.max' => 'Durasi maksimal 15 detik.',
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
            $event = $this->service->sendManualNotification($station, $user, $request->all());

            return response()->json([
                'success' => $event->status !== TvAlertEvent::STATUS_FAILED,
                'status' => $event->status,
                'message' => $event->status === TvAlertEvent::STATUS_FAILED
                    ? ($event->error_message ?? 'Gagal mengirim notifikasi ke konsol.')
                    : 'Notifikasi berhasil dikirim ke konsol.',
                'data' => [
                    'event_id' => $event->id,
                    'station_id' => $event->station_id,
                    'station_name' => $event->station_name,
                    'preset' => $event->message_preset,
                    'title' => $event->message_title,
                    'message' => $event->message_body,
                    'status' => $event->status,
                    'result_code' => $event->result_code,
                    'driver_type' => $event->driver_type,
                    'target_channel' => $event->target_channel,
                    'created_at' => $event->created_at ? $event->created_at->toIso8601String() : null,
                ],
            ], $event->status === TvAlertEvent::STATUS_FAILED ? 422 : 200);
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
     * Get recent console notification events for a station.
     * Endpoint: GET /admin/stations/{station}/console-notifications
     */
    public function history(int $stationId, Request $request): JsonResponse
    {
        $station = Device::find($stationId);
        if (!$station) {
            return response()->json([
                'success' => false,
                'error_code' => 'STATION_NOT_FOUND',
                'message' => 'Perangkat/stasiun tidak ditemukan.',
            ], 404);
        }

        $events = TvAlertEvent::where('station_id', $stationId)
            ->whereIn('target_channel', ['CONSOLE', 'BOTH'])
            ->latest('id')
            ->limit(20)
            ->get();

        return response()->json([
            'success' => true,
            'station_id' => $stationId,
            'count' => $events->count(),
            'events' => $events,
        ], 200);
    }

    /**
     * Get live console status for a station.
     * Endpoint: GET /admin/stations/{station}/console-status
     */
    public function status(int $stationId, Request $request): JsonResponse
    {
        $station = Device::find($stationId);
        if (!$station) {
            return response()->json([
                'success' => false,
                'error_code' => 'STATION_NOT_FOUND',
                'message' => 'Perangkat/stasiun tidak ditemukan.',
            ], 404);
        }

        $statusData = $this->service->getConsoleStatus($station);

        return response()->json([
            'success' => true,
            'data' => $statusData,
        ], 200);
    }
}
