<?php

namespace App\Http\Controllers;

use App\Models\AutomationIntent;
use App\Models\Device;
use App\Models\Transaction;
use App\Services\Hardware\Automation\AutomationIntentService;
use App\Services\Hardware\Automation\AutomationStatusService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StationAutomationController extends Controller
{
    protected AutomationStatusService $statusService;
    protected AutomationIntentService $intentService;

    public function __construct(
        AutomationStatusService $statusService,
        AutomationIntentService $intentService
    ) {
        $this->statusService = $statusService;
        $this->intentService = $intentService;
    }

    /**
     * Get masked cashier hardware status badge and alert.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function getStatus(int $id): JsonResponse
    {
        $station = Device::find($id);
        if (!$station) {
            return response()->json([
                'status' => 'error',
                'message' => 'Stasiun tidak ditemukan',
            ], 404);
        }

        $data = $this->statusService->getCashierStatus($station);

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * Cashier manual action (bounded retry or manual override).
     *
     * @param int $id
     * @param Request $request
     * @return JsonResponse
     */
    public function manualAction(int $id, Request $request): JsonResponse
    {
        $request->validate([
            'action' => 'required|string|in:RETRY_LAST_INTENT,OVERRIDE_OFF',
            'reason' => 'nullable|string|max:255',
        ]);

        $station = Device::find($id);
        if (!$station) {
            return response()->json([
                'status' => 'error',
                'message' => 'Stasiun tidak ditemukan',
            ], 404);
        }

        $action = $request->input('action');
        $reason = $request->input('reason', 'Manual cashier action');
        $user = auth()->user();

        if ($action === 'RETRY_LAST_INTENT') {
            $intent = AutomationIntent::where('station_id', $station->id)
                ->whereIn('status', [AutomationIntent::STATUS_FAILED, AutomationIntent::STATUS_RETRY_PENDING])
                ->latest('id')
                ->first();

            if (!$intent) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Tidak ada perintah gagal atau tertunda yang dapat dicoba ulang.',
                ], 400);
            }

            $intent->update([
                'status' => AutomationIntent::STATUS_PENDING,
                'attempts' => 0,
                'scheduled_at' => Carbon::now(),
                'next_retry_at' => null,
                'last_error_message' => "Manual retry requested by user #{$user->id}: {$reason}",
                'updated_at' => Carbon::now(),
            ]);

            Log::info("[AUTOMATION_MANUAL] Cashier manual retry scheduled", [
                'station_id' => $station->id,
                'intent_id' => $intent->id,
                'user_id' => $user->id,
                'reason' => $reason,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Perintah retry telah dimasukkan ke dalam antrean eksekusi.',
                'data' => [
                    'intent_id' => $intent->id,
                    'status' => AutomationIntent::STATUS_PENDING,
                    'scheduled_at' => Carbon::now()->toDateTimeString(),
                ],
            ]);
        }

        if ($action === 'OVERRIDE_OFF') {
            // Guardrail: Kasir cannot override OFF while an active billing session is RUNNING
            $activeTx = $station->activeTransaction();
            if ($activeTx && $activeTx->billing_status === Transaction::BILLING_RUNNING) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Sesi billing masih berjalan aktif. Harap hentikan sesi terlebih dahulu sebelum mematikan lampu.',
                ], 400);
            }

            $intent = $this->intentService->recordIntent(
                $station,
                null,
                AutomationIntent::EVENT_MANUAL_OVERRIDE,
                AutomationIntent::STATE_OFF,
                Carbon::now()
            );

            Log::info("[AUTOMATION_MANUAL] Cashier override OFF requested", [
                'station_id' => $station->id,
                'intent_id' => $intent->id,
                'user_id' => $user->id,
                'reason' => $reason,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Perintah mematikan lampu telah dimasukkan ke dalam antrean.',
                'data' => [
                    'intent_id' => $intent->id,
                    'status' => $intent->status,
                    'scheduled_at' => Carbon::now()->toDateTimeString(),
                ],
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Aksi tidak valid.',
        ], 422);
    }
}
