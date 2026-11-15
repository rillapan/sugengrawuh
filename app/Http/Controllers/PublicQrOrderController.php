<?php

namespace App\Http\Controllers;

use App\Models\FnbOrder;
use App\Services\Fnb\QrOrderService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PublicQrOrderController extends Controller
{
    protected QrOrderService $qrOrderService;

    public function __construct(QrOrderService $qrOrderService)
    {
        $this->qrOrderService = $qrOrderService;
    }

    /**
     * Display the public menu for a station or show the no-session redirect screen.
     */
    public function showStationMenu(Request $request, string $code)
    {
        $data = $this->qrOrderService->getStationMenuData($code);

        if (!$data['hasActiveSession']) {
            return view('qr_order.no_session', [
                'location' => $data['location'],
                'playerName' => $data['playerName'] ?? null,
            ]);
        }

        return view('qr_order.menu', [
            'location' => $data['location'],
            'token' => $data['token'],
            'fnbs' => $data['fnbs'],
            'playerName' => $data['playerName'] ?? null,
            'activeOrders' => $data['activeOrders'] ?? collect(),
            'sessionType' => $data['sessionType'] ?? null,
        ]);
    }

    /**
     * Retrieve active orders for an active station session.
     */
    public function getActiveOrders(Request $request, string $code)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        try {
            $result = $this->qrOrderService->getActiveStationOrders($code, (string) $request->input('token'));

            return response()->json([
                'success' => true,
                'playerName' => $result['playerName'],
                'orders' => $result['orders'],
            ]);
        } catch (AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 403);
        }
    }

    /**
     * Submit an order for an active station session.
     */
    public function submitOrder(Request $request, string $code)
    {
        $request->validate([
            'session_token' => 'required|string',
            'customer_name' => 'required|string|max:100',
            'notes' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.fnb_id' => 'required|integer',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.notes' => 'nullable|string|max:255',
            'items.*.expected_price' => 'nullable|numeric',
            'idempotency_key' => 'nullable|string|max:100',
        ]);

        try {
            $order = $this->qrOrderService->submitStationOrder($code, $request->all());

            if ($request->expectsJson() || $request->isJson()) {
                return response()->json([
                    'success' => true,
                    'order' => $order->makeHidden(['billing_name']),
                    'redirect' => route('public.qr.status', $order->order_number),
                ], 201);
            }

            return redirect()->route('public.qr.status', $order->order_number);
        } catch (AuthorizationException $e) {
            if ($request->expectsJson() || $request->isJson()) {
                return response()->json(['message' => $e->getMessage()], 403);
            }
            abort(403, $e->getMessage());
        } catch (InvalidArgumentException $e) {
            if ($request->expectsJson() || $request->isJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            return back()->withInput()->withErrors(['order_error' => $e->getMessage()]);
        }
    }

    /**
     * Display the status of an F&B order for the customer without exposing billing details.
     */
    public function showOrderStatus(Request $request, string $orderNumber)
    {
        $order = FnbOrder::where('order_number', $orderNumber)
            ->with(['items.fnb', 'location'])
            ->firstOrFail();

        return view('qr_order.status', [
            'order' => $order,
        ]);
    }
}
