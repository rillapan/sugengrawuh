<?php

namespace Tests\Feature\Fnb;

use App\Models\FnbOrder;
use App\Models\Location;
use App\Models\User;
use App\Models\WorkShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QrQueueNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $kasir;
    protected User $fnbStaff;
    protected User $owner;
    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $shift = WorkShift::create([
            'nama_shift' => 'Shift Pagi',
            'jam_mulai' => '08:00:00',
            'jam_selesai' => '16:00:00',
        ]);

        $this->admin = User::factory()->create(['role' => 'admin', 'email' => 'admin-qr@test.local']);
        $this->kasir = User::factory()->create(['role' => 'kasir', 'is_fnb_staff' => false, 'work_shift_id' => $shift->id, 'email' => 'kasir-qr@test.local']);
        $this->fnbStaff = User::factory()->create(['role' => 'kasir', 'is_fnb_staff' => true, 'work_shift_id' => $shift->id, 'email' => 'fnb-qr@test.local']);
        $this->owner = User::factory()->create(['role' => 'owner', 'email' => 'owner-qr@test.local']);

        $this->location = Location::create([
            'name' => 'Station 01',
            'code' => 'STN-01',
            'type' => 'station',
        ]);
    }

    public function test_unauthenticated_user_cannot_poll_fnb_queue(): void
    {
        $this->get(route('fnb.queue.poll'))
            ->assertRedirect(route('login'));
    }

    public function test_authorized_roles_can_poll_fnb_queue_and_count_pending_qr_orders(): void
    {
        // Non-QR PENDING order should NOT be counted in pending_qr_count
        FnbOrder::create([
            'order_number' => 'ORD-CASHIER-001',
            'source' => FnbOrder::SOURCE_CASHIER,
            'customer_name' => 'Walk-in',
            'location_id' => $this->location->id,
            'status' => FnbOrder::STATUS_PENDING,
            'payment_status' => FnbOrder::PAYMENT_UNPAID,
            'total_amount' => 10000,
        ]);

        // Accepted QR order should NOT be counted in pending_qr_count
        FnbOrder::create([
            'order_number' => 'ORD-QR-ACCEPTED',
            'source' => FnbOrder::SOURCE_QR,
            'customer_name' => 'Player 1',
            'location_id' => $this->location->id,
            'status' => FnbOrder::STATUS_ACCEPTED,
            'payment_status' => FnbOrder::PAYMENT_UNPAID,
            'total_amount' => 15000,
        ]);

        // Pending QR order SHOULD be counted
        $pendingQr = FnbOrder::create([
            'order_number' => 'ORD-QR-PENDING',
            'source' => FnbOrder::SOURCE_QR,
            'customer_name' => 'Player 2',
            'location_id' => $this->location->id,
            'status' => FnbOrder::STATUS_PENDING,
            'payment_status' => FnbOrder::PAYMENT_UNPAID,
            'total_amount' => 20000,
        ]);

        foreach ([$this->admin, $this->kasir, $this->fnbStaff, $this->owner] as $user) {
            $res = $this->actingAs($user)
                ->getJson(route('fnb.queue.poll'))
                ->assertOk()
                ->assertJson([
                    'pending_qr_count' => 1,
                    'total_orders' => 3,
                ])
                ->assertJsonPath('pending_qr_orders.0.id', $pendingQr->id)
                ->assertJsonPath('pending_qr_orders.0.order_number', 'ORD-QR-PENDING')
                ->assertJsonPath('pending_qr_orders.0.accept_url', route('fnb.queue.status', $pendingQr->id))
                ->assertJsonPath('pending_qr_orders.0.cancel_url', route('fnb.queue.cancel', $pendingQr->id));

            $this->assertNotEmpty($res->json('orders_signature'));
        }

        $firstSig = $this->actingAs($this->fnbStaff)->getJson(route('fnb.queue.poll'))->json('orders_signature');

        // Confirm ("Terima") the pending QR order -> count drops to 0 and signature updates
        $pendingQr->update(['status' => FnbOrder::STATUS_ACCEPTED]);

        $afterRes = $this->actingAs($this->fnbStaff)
            ->getJson(route('fnb.queue.poll'))
            ->assertOk()
            ->assertJson([
                'pending_qr_count' => 0,
                'pending_qr_orders' => [],
                'total_orders' => 3,
            ]);

        $this->assertNotSame($firstSig, $afterRes->json('orders_signature'));
    }

    public function test_queue_page_renders_qr_notification_controls_and_popup_modal(): void
    {
        FnbOrder::create([
            'order_number' => 'ORD-QR-ALERT',
            'source' => FnbOrder::SOURCE_QR,
            'customer_name' => 'Player QR',
            'location_id' => $this->location->id,
            'status' => FnbOrder::STATUS_PENDING,
            'payment_status' => FnbOrder::PAYMENT_UNPAID,
            'total_amount' => 12000,
        ]);

        $response = $this->actingAs($this->fnbStaff)
            ->get(route('fnb.queue.index'))
            ->assertOk();

        $response->assertSee('id="btnToggleQrSound"', false);
        $response->assertSee('id="qrIncomingModal"', false);
        $response->assertSee('id="qrIncomingModalBody"', false);
        $response->assertDontSee('id="qrPendingAlertBanner"', false);
        $response->assertSee('id="fnbQueueTableBody"', false);
        $response->assertSee('playQrNotificationChime', false);
        $response->assertSee(json_encode(route('fnb.queue.poll')), false);
    }

    public function test_poll_and_page_include_cashier_orders_for_sound_alert_notification(): void
    {
        // 1. Create a CASHIER order with status ACCEPTED (e.g. from Paket transaction)
        $cashierPaketOrder = FnbOrder::create([
            'order_number' => 'ORD-CASHIER-PAKET',
            'source' => FnbOrder::SOURCE_CASHIER,
            'customer_name' => 'Paket Player',
            'location_id' => $this->location->id,
            'status' => FnbOrder::STATUS_ACCEPTED,
            'payment_status' => FnbOrder::PAYMENT_PAID,
            'total_amount' => 15000,
        ]);

        // 2. Create a CASHIER order with status PENDING (e.g. standalone cashier order)
        $cashierPendingOrder = FnbOrder::create([
            'order_number' => 'ORD-CASHIER-PENDING',
            'source' => FnbOrder::SOURCE_CASHIER,
            'customer_name' => 'Walk-in Cashier',
            'location_id' => $this->location->id,
            'status' => FnbOrder::STATUS_PENDING,
            'payment_status' => FnbOrder::PAYMENT_UNPAID,
            'total_amount' => 8000,
        ]);

        // 3. Create a CASHIER order with status PREPARING (already in cooking/preparing -> should NOT alert)
        FnbOrder::create([
            'order_number' => 'ORD-CASHIER-PREPARING',
            'source' => FnbOrder::SOURCE_CASHIER,
            'customer_name' => 'Cooking Player',
            'location_id' => $this->location->id,
            'status' => FnbOrder::STATUS_PREPARING,
            'payment_status' => FnbOrder::PAYMENT_PAID,
            'total_amount' => 10000,
        ]);

        // Poll endpoint should return pending_cashier_count = 2 and pending_alert_count = 2
        $pollRes = $this->actingAs($this->fnbStaff)
            ->getJson(route('fnb.queue.poll'))
            ->assertOk()
            ->assertJson([
                'pending_qr_count' => 0,
                'pending_cashier_count' => 2,
                'pending_alert_count' => 2,
                'total_orders' => 3,
            ]);

        $alertOrders = $pollRes->json('pending_alert_orders');
        $this->assertCount(2, $alertOrders);
        $orderNumbers = collect($alertOrders)->pluck('order_number')->all();
        $this->assertContains('ORD-CASHIER-PAKET', $orderNumbers);
        $this->assertContains('ORD-CASHIER-PENDING', $orderNumbers);

        // When cashier order is advanced to PREPARING, count drops
        $cashierPaketOrder->update(['status' => FnbOrder::STATUS_PREPARING]);

        $this->actingAs($this->fnbStaff)
            ->getJson(route('fnb.queue.poll'))
            ->assertOk()
            ->assertJson([
                'pending_cashier_count' => 1,
                'pending_alert_count' => 1,
            ]);

        // When all active incoming cashier orders are processed to PREPARING, alert drops to 0
        $cashierPendingOrder->update(['status' => FnbOrder::STATUS_PREPARING]);

        $this->actingAs($this->fnbStaff)
            ->getJson(route('fnb.queue.poll'))
            ->assertOk()
            ->assertJson([
                'pending_cashier_count' => 0,
                'pending_alert_count' => 0,
                'pending_alert_orders' => [],
            ]);
    }
}
