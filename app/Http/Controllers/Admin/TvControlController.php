<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\TvControlException;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Playstation;
use App\Models\TvAlertEvent;
use App\Services\Hardware\Drivers\MockSamsungTvDriver;
use App\Services\Hardware\TvAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TvControlController extends Controller
{
    protected TvAlertService $tvAlertService;

    public function __construct(TvAlertService $tvAlertService)
    {
        $this->tvAlertService = $tvAlertService;
    }

    /**
     * Send manual alert to a PlayStation station's Samsung TV.
     * Endpoint: POST /admin/stations/{station}/tv-alerts
     */
    public function sendAlert(int $station, Request $request): JsonResponse
    {
        $stationModel = Device::with(['playstations', 'playstation'])->find($station);
        if (!$stationModel) {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'error_code' => 'STATION_NOT_FOUND',
                'message' => 'Perangkat/stasiun tidak ditemukan.',
            ], 404);
        }

        // Normalize preset field (support both 'preset' and 'message_preset', and '10_MIN_LEFT' alias)
        $presetValue = $request->input('preset', $request->input('message_preset'));
        if ($presetValue !== null) {
            $normalizedPreset = strtoupper(trim((string) $presetValue));
            if ($normalizedPreset === '10_MIN_LEFT') {
                $normalizedPreset = TvAlertEvent::PRESET_REMAINING_10_MIN;
            }
            $request->merge(['preset' => $normalizedPreset]);
        }

        $validator = Validator::make($request->all(), [
            'preset' => 'required|string|in:REMAINING_10_MIN,ALMOST_FINISHED,LOWER_VOLUME,CONTACT_CASHIER,CUSTOM',
            'custom_message' => 'nullable|required_if:preset,CUSTOM|string|max:200',
            'duration_seconds' => 'nullable|integer|min:5|max:15',
        ], [
            'preset.required' => 'Preset pesan peringatan wajib dipilih.',
            'preset.in' => 'Preset pesan peringatan tidak valid.',
            'custom_message.required_if' => 'Pesan khusus wajib diisi apabila memilih preset CUSTOM.',
            'custom_message.max' => 'Pesan khusus maksimal 200 karakter.',
            'duration_seconds.integer' => 'Durasi tampilan harus berupa angka.',
            'duration_seconds.min' => 'Durasi tampilan minimal 5 detik.',
            'duration_seconds.max' => 'Durasi tampilan maksimal 15 detik.',
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
            $event = $this->tvAlertService->sendManualAlert($stationModel, $request->user(), $request->all());

            $eventData = [
                'event_id' => $event->id,
                'id' => $event->id,
                'station_id' => $event->station_id,
                'station_name' => $event->station_name,
                'transaction_id' => $event->transaction_id,
                'event_type' => $event->event_type,
                'command' => $event->command,
                'message_preset' => $event->message_preset,
                'message_title' => $event->message_title,
                'message_body' => $event->message_body,
                'message_text' => $event->message_body,
                'display_duration_seconds' => $event->display_duration_seconds,
                'duration_seconds' => $event->display_duration_seconds,
                'simulated_volume' => $event->simulated_volume,
                'simulated_muted' => (bool) $event->simulated_muted,
                'control_mode' => $event->control_mode,
                'driver_type' => $event->driver_type,
                'status' => $event->status,
                'result_code' => $event->result_code,
                'error_message' => $event->error_message,
                'session_generation' => (int) $event->session_generation,
                'created_at' => $event->created_at ? $event->created_at->toIso8601String() : now()->toIso8601String(),
            ];

            if ($event->status === TvAlertEvent::STATUS_FAILED) {
                return response()->json([
                    'success' => false,
                    'status' => 'error',
                    'error_code' => $event->result_code ?? 'DRIVER_COMMAND_FAILED',
                    'message' => $event->error_message ?? 'Gagal mengirim peringatan ke simulator TV.',
                    'data' => $eventData,
                ], 422);
            }

            return response()->json([
                'success' => true,
                'status' => 'success',
                'message' => 'Peringatan berhasil dicatat (PENDING) dan siap diambil oleh aplikasi TV.',
                'data' => $eventData,
            ], 201);
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
     * Send volume/mute command to a PlayStation station's Samsung TV.
     * Endpoint: POST /admin/stations/{station}/tv-volume
     */
    public function sendVolume(int $station, Request $request): JsonResponse
    {
        $stationModel = Device::with(['playstations', 'playstation'])->find($station);
        if (!$stationModel) {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'error_code' => 'STATION_NOT_FOUND',
                'message' => 'Perangkat/stasiun tidak ditemukan.',
            ], 404);
        }

        $commandValue = $request->input('command', $request->input('action'));
        if ($commandValue !== null) {
            $request->merge(['command' => strtoupper(trim((string) $commandValue))]);
        }

        $validator = Validator::make($request->all(), [
            'command' => 'required|string|in:VOLUME_DOWN,VOLUME_UP,MUTE_TOGGLE',
            'step' => 'nullable|integer|min:1|max:25',
        ], [
            'command.required' => 'Perintah volume wajib diisi.',
            'command.in' => 'Perintah volume harus salah satu dari: VOLUME_DOWN, VOLUME_UP, MUTE_TOGGLE.',
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
            $result = $this->tvAlertService->sendVolumeCommand(
                $stationModel,
                $request->user(),
                (string) $request->input('command'),
                $request->only(['step'])
            );

            /** @var TvAlertEvent $event */
            $event = $result['event'];

            $payload = [
                'event_id' => $event->id,
                'id' => $event->id,
                'station_id' => $stationModel->id,
                'station_name' => $stationModel->nama,
                'command' => $event->command,
                'status' => $event->status,
                'result_code' => $event->result_code,
                'volume' => (int) $result['volume'],
                'mute' => (bool) $result['mute'],
                'muted' => (bool) $result['mute'],
                'simulated_volume' => (int) $event->simulated_volume,
                'simulated_muted' => (bool) $event->simulated_muted,
                'simulated_state' => $result['simulated_state'],
                'control_mode' => $event->control_mode,
                'driver_type' => $event->driver_type,
                'session_generation' => (int) $event->session_generation,
                'created_at' => $event->created_at ? $event->created_at->toIso8601String() : now()->toIso8601String(),
            ];

            if (!$result['success'] || $event->status === TvAlertEvent::STATUS_FAILED) {
                return response()->json([
                    'success' => false,
                    'status' => 'error',
                    'error_code' => $event->result_code ?? 'VOLUME_COMMAND_FAILED',
                    'message' => $result['message'],
                    'data' => $payload,
                ], 422);
            }

            return response()->json([
                'success' => true,
                'status' => 'success',
                'message' => $result['message'],
                'data' => $payload,
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
     * Get live TV control status, simulated state, consumer config, and recent event history for a station.
     * Endpoint: GET /admin/stations/{station}/tv-control-status
     */
    public function status(int $station, Request $request): JsonResponse
    {
        $stationModel = Device::with(['playstations', 'playstation'])->find($station);
        if (!$stationModel) {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'error_code' => 'STATION_NOT_FOUND',
                'message' => 'Perangkat/stasiun tidak ditemukan.',
            ], 404);
        }

        // Safely evaluate 10-minute automatic alerts and sweep expired/cancelled pending events
        try {
            $this->tvAlertService->evaluateAutoAlerts();
        } catch (\Throwable $e) {
            // Non-blocking fallback
        }

        $statusData = $this->tvAlertService->getStationControlStatus($stationModel, 15);
        $simulatorConnectionStatus = $statusData['simulator_online'] ? 'CONNECTED' : 'WAITING_CONSUMER';

        $historyItems = collect($statusData['history'])->map(function (TvAlertEvent $ev) {
            return [
                'id' => $ev->id,
                'event_id' => $ev->id,
                'station_id' => $ev->station_id,
                'station_name' => $ev->station_name,
                'transaction_id' => $ev->transaction_id,
                'session_generation' => (int) $ev->session_generation,
                'actor_role' => $ev->actor_role,
                'event_type' => $ev->event_type,
                'command' => $ev->command,
                'message_preset' => $ev->message_preset,
                'message_title' => $ev->message_title,
                'message_body' => $ev->message_body,
                'display_duration_seconds' => (int) $ev->display_duration_seconds,
                'simulated_volume' => (int) $ev->simulated_volume,
                'simulated_muted' => (bool) $ev->simulated_muted,
                'control_mode' => $ev->control_mode,
                'driver_type' => $ev->driver_type,
                'status' => $ev->status,
                'result_code' => $ev->result_code,
                'error_message' => $ev->error_message,
                'scheduled_at' => $ev->scheduled_at ? $ev->scheduled_at->toIso8601String() : null,
                'delivered_at' => $ev->delivered_at ? $ev->delivered_at->toIso8601String() : null,
                'acked_at' => $ev->acked_at ? $ev->acked_at->toIso8601String() : null,
                'cancelled_at' => $ev->cancelled_at ? $ev->cancelled_at->toIso8601String() : null,
                'created_at' => $ev->created_at ? $ev->created_at->toIso8601String() : null,
                'created_at_formatted' => $ev->created_at ? $ev->created_at->format('H:i:s') : '-',
            ];
        })->values()->all();

        $latestEventItem = !empty($historyItems) ? $historyItems[0] : null;

        $consumerConfig = [
            'station_id' => $stationModel->id,
            'device_token' => $statusData['consumer_token'],
            'dev_token' => $statusData['dev_token'],
            'poll_url' => route('api.tv-agent.events.index', ['station_id' => $stationModel->id]),
            'ack_url_template' => url('/api/tv-agent/events/{event_id}/ack'),
            'tv_lab_url' => route('admin.tv-lab.index', ['station_id' => $stationModel->id]),
        ];

        $dataPayload = [
            'station_id' => $statusData['station_id'],
            'station_name' => $statusData['station_name'],
            'station_status' => $statusData['station_status'],
            'station_category' => $statusData['station_category'],
            'playstation_names' => $stationModel->playstation_names,
            'session_generation' => $statusData['session_generation'],
            'has_active_session' => $statusData['has_active_session'],
            'transaction' => $statusData['transaction'],
            'tv_device' => $statusData['tv_device'],
            'control_mode' => 'MOCK',
            'driver_type' => MockSamsungTvDriver::DRIVER_TYPE,
            'physical_display_verified' => false,
            'simulator_online' => $statusData['simulator_online'],
            'simulator_connection_status' => $simulatorConnectionStatus,
            'tv_state' => $statusData['tv_state'],
            'simulated_volume' => (int) ($statusData['tv_state']['volume'] ?? 30),
            'simulated_muted' => (bool) ($statusData['tv_state']['mute'] ?? false),
            'latest_event' => $latestEventItem,
            'history' => $historyItems,
            'consumer_config' => $consumerConfig,
        ];

        return response()->json([
            'success' => true,
            'status' => 'success',
            'data' => $dataPayload,
        ]);
    }

    /**
     * Render the 16:9 Browser TV Simulator Lab page (/admin/tv-lab).
     * Endpoint: GET /admin/tv-lab
     */
    public function labIndex(Request $request)
    {
        try {
            $this->tvAlertService->evaluateAutoAlerts();
        } catch (\Throwable $e) {
            // Non-blocking fallback
        }

        $allDevices = Device::with(['playstations', 'playstation', 'activeDisplayTv.physicalDevice'])
            ->orderBy('id', 'asc')
            ->get();

        $psStations = $allDevices->filter(function (Device $device) {
            return $device->getStationCategory() === Playstation::CATEGORY_PLAYSTATION;
        })->values();

        $stationsPayload = $psStations->map(function (Device $station) {
            $statusInfo = $this->tvAlertService->getStationControlStatus($station, 15);
            return [
                'id' => $station->id,
                'nama' => $station->nama,
                'status' => $station->status,
                'playstation_names' => $station->playstation_names ?: 'PlayStation',
                'session_generation' => $statusInfo['session_generation'],
                'has_active_session' => $statusInfo['has_active_session'],
                'transaction' => $statusInfo['transaction'],
                'tv_device' => $statusInfo['tv_device'],
                'device_token' => $statusInfo['consumer_token'],
                'dev_token' => $statusInfo['dev_token'],
                'tv_state' => $statusInfo['tv_state'],
                'simulator_online' => $statusInfo['simulator_online'],
                'status_url' => route('admin.stations.tv-control.status', ['station' => $station->id]),
            ];
        })->values()->all();

        $selectedStationId = (int) $request->query('station_id', 0);
        if ($selectedStationId === 0 && !empty($stationsPayload)) {
            // Prefer first in-use station if available, otherwise first station
            $inUse = collect($stationsPayload)->firstWhere('status', 'Digunakan');
            $selectedStationId = $inUse ? (int) $inUse['id'] : (int) $stationsPayload[0]['id'];
        }

        return view('admin.hardware.tv_lab', [
            'title' => 'Samsung TV Lab — Simulator Layar 16:9',
            'active' => 'tv-lab',
            'stations' => $psStations,
            'stationsPayload' => $stationsPayload,
            'selectedStationId' => $selectedStationId,
            'presets' => TvAlertEvent::getPresetMessages(),
        ]);
    }
}
