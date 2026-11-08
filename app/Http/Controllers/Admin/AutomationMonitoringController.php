<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AutomationExecutionLog;
use App\Models\AutomationIntent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AutomationMonitoringController extends Controller
{
    /**
     * Admin intent inspection endpoint.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function intents(Request $request): JsonResponse
    {
        $user = auth()->user();
        if (!$user || !in_array($user->role, ['admin', 'owner'], true)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $query = AutomationIntent::query()->latest('id');

        if ($request->filled('station_id')) {
            $query->where('station_id', $request->input('station_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('event_type')) {
            $query->where('event_type', $request->input('event_type'));
        }

        $perPage = min(50, max(1, (int) $request->input('per_page', 15)));
        $intents = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $intents,
        ]);
    }

    /**
     * Admin execution logs telemetry endpoint.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function logs(Request $request): JsonResponse
    {
        $user = auth()->user();
        if (!$user || !in_array($user->role, ['admin', 'owner'], true)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $query = AutomationExecutionLog::query()->latest('id');

        if ($request->filled('station_id')) {
            $query->where('station_id', $request->input('station_id'));
        }

        if ($request->filled('execution_status')) {
            $query->where('execution_status', $request->input('execution_status'));
        }

        if ($request->filled('intent_id')) {
            $query->where('intent_id', $request->input('intent_id'));
        }

        $perPage = min(50, max(1, (int) $request->input('per_page', 15)));
        $logs = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $logs,
        ]);
    }
}
