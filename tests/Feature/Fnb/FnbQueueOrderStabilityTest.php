<?php

namespace Tests\Feature\Fnb;

use App\Models\Fnb;
use App\Models\FnbOrder;
use App\Models\FnbOrderItem;
use App\Models\Location;
use App\Models\User;
use App\Models\WorkShift;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FnbQueueOrderStabilityTest extends TestCase
{
    use RefreshDatabase;

    protected User $kasir;
    protected User $fnbStaff;
    protected Location $location;
    protected Fnb $fnbItem;

    protected function setUp(): void
    {
        parent::setUp();

        $shift = WorkShift::create([
            'nama_shift' => 'Shift Pagi',
            'jam_mulai' => '08:00:00',
            'jam_selesai' => '16:00:00',
        ]);

        $this->kasir = User::factory()->create([
            'role' => 'kasir',
            'is_fnb_staff' => false,
            'work_shift_id' => $shift->id,
            'email' => 'kasir-queue@test.local',
        ]);

        $this->fnbStaff = User::factory()->create([
            'role' => 'kasir',
            'is_fnb_staff' => true,
            'work_shift_id' => $shift->id,
            'email' => 'fnb-queue@test.local',
        ]);

        $this->location = Location::create([
            'name' => 'Station 01',
            'code' => 'STN-01',
            'type' => 'station',
        ]);

        $this->fnbItem = Fnb::create([
            'nama' => 'Teh Manis',
            'kategori' => 'Minuman',
            'harga_beli' => 2000,
            'harga_jual' => 5000,
            'stok' => 50,
            'is_unlimited' => false,
            'is_active' => true,
        ]);
    }

    protected function createOrder(string $orderNumber, string $customerName, Carbon $createdAt, string $status = FnbOrder::STATUS_PENDING, string $paymentStatus = FnbOrder::PAYMENT_UNPAID): FnbOrder
    {
        $order = FnbOrder::create([
            'order_number' => $orderNumber,
            'source' => FnbOrder::SOURCE_CASHIER,
            'customer_name' => $customerName,
            'location_id' => $this->location->id,
            'status' => $status,
            'payment_status' => $paymentStatus,
            'total_amount' => 5000,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        FnbOrderItem::create([
            'fnb_order_id' => $order->id,
            'fnb_id' => $this->fnbItem->id,
            'qty' => 1,
            'unit_price_beli' => 2000,
            'unit_price_jual' => 5000,
            'subtotal' => 5000,
            'payment_status' => $paymentStatus,
        ]);

        return $order;
    }

    /**
     * AC 1: Orders are sorted by created_at DESC with secondary id DESC.
     */
    public function test_queue_orders_are_sorted_by_created_at_desc_and_id_desc(): void
    {
        $time1 = Carbon::parse('2026-11-15 10:00:00');
        $time2 = Carbon::parse('2026-11-15 10:15:00');
        $time3 = Carbon::parse('2026-11-15 10:30:00');

        $orderOld = $this->createOrder('ORD-OLD', 'Pelanggan 1', $time1);
        $orderMid = $this->createOrder('ORD-MID', 'Pelanggan 2', $time2);
        $orderNew = $this->createOrder('ORD-NEW', 'Pelanggan 3', $time3);

        $response = $this->actingAs($this->kasir)->get(route('fnb.queue.index'));
        $response->assertOk();

        // In HTML, ORD-NEW must appear before ORD-MID, which appears before ORD-OLD
        $content = $response->getContent();
        $posNew = strpos($content, 'ORD-NEW');
        $posMid = strpos($content, 'ORD-MID');
        $posOld = strpos($content, 'ORD-OLD');

        $this->assertNotFalse($posNew);
        $this->assertNotFalse($posMid);
        $this->assertNotFalse($posOld);
        $this->assertTrue($posNew < $posMid, 'Newest order should appear before mid order');
        $this->assertTrue($posMid < $posOld, 'Mid order should appear before old order');

        // Test tie-breaking by id DESC when created_at is identical
        $sameTime = Carbon::parse('2026-11-15 11:00:00');
        $orderTie1 = $this->createOrder('ORD-TIE-1', 'Tie 1', $sameTime);
        $orderTie2 = $this->createOrder('ORD-TIE-2', 'Tie 2', $sameTime);

        $responseTie = $this->actingAs($this->kasir)->get(route('fnb.queue.index'));
        $contentTie = $responseTie->getContent();
        $posTie1 = strpos($contentTie, 'ORD-TIE-1');
        $posTie2 = strpos($contentTie, 'ORD-TIE-2');

        $this->assertTrue($orderTie2->id > $orderTie1->id);
        $this->assertTrue($posTie2 < $posTie1, 'Higher id should appear before lower id when created_at is equal');
    }

    /**
     * AC 2: Changing payment_status does not change queue position.
     */
    public function test_changing_payment_status_does_not_shift_order_position(): void
    {
        $time1 = Carbon::parse('2026-11-15 10:00:00');
        $time2 = Carbon::parse('2026-11-15 10:15:00');

        $orderOld = $this->createOrder('ORD-PAY-OLD', 'Pelanggan Lama', $time1, FnbOrder::STATUS_ACCEPTED, FnbOrder::PAYMENT_UNPAID);
        $orderNew = $this->createOrder('ORD-PAY-NEW', 'Pelanggan Baru', $time2, FnbOrder::STATUS_ACCEPTED, FnbOrder::PAYMENT_UNPAID);

        // Before payment change
        $resBefore = $this->actingAs($this->kasir)->get(route('fnb.queue.index'));
        $contentBefore = $resBefore->getContent();
        $this->assertTrue(strpos($contentBefore, 'ORD-PAY-NEW') < strpos($contentBefore, 'ORD-PAY-OLD'));

        // Change older order to PAID (via pay endpoint or direct update) with later updated_at
        $this->travel(1)->hours();
        $orderOld->update([
            'payment_status' => FnbOrder::PAYMENT_PAID,
            'updated_at' => now(),
        ]);

        // After payment change on older order, ORD-PAY-NEW must still remain strictly before ORD-PAY-OLD
        $resAfter = $this->actingAs($this->kasir)->get(route('fnb.queue.index'));
        $contentAfter = $resAfter->getContent();
        $posNew = strpos($contentAfter, 'ORD-PAY-NEW');
        $posOld = strpos($contentAfter, 'ORD-PAY-OLD');

        $this->assertTrue($posNew < $posOld, 'Payment status change on older order must not move it above newer order');
    }

    /**
     * AC 3: Changing kitchen status (PENDING -> ACCEPTED -> PREPARING -> READY -> DELIVERED) does not change queue position.
     */
    public function test_changing_kitchen_status_does_not_shift_order_position(): void
    {
        $time1 = Carbon::parse('2026-11-15 10:00:00');
        $time2 = Carbon::parse('2026-11-15 10:15:00');

        $orderOld = $this->createOrder('ORD-KITCHEN-OLD', 'Old Player', $time1, FnbOrder::STATUS_PENDING);
        $orderNew = $this->createOrder('ORD-KITCHEN-NEW', 'New Player', $time2, FnbOrder::STATUS_PENDING);

        $statuses = [
            FnbOrder::STATUS_ACCEPTED,
            FnbOrder::STATUS_PREPARING,
            FnbOrder::STATUS_READY,
            FnbOrder::STATUS_DELIVERED,
        ];

        foreach ($statuses as $status) {
            $this->travel(5)->minutes();
            $orderOld->update([
                'status' => $status,
                'updated_at' => now(),
            ]);

            $res = $this->actingAs($this->kasir)->get(route('fnb.queue.index'));
            $content = $res->getContent();
            $posNew = strpos($content, 'ORD-KITCHEN-NEW');
            $posOld = strpos($content, 'ORD-KITCHEN-OLD');

            $this->assertTrue($posNew < $posOld, "Kitchen status {$status} on older order must not move it above newer order");
        }
    }

    /**
     * AC 4 & 5: New order appears on top, refresh maintains identical ordering.
     */
    public function test_new_order_appears_on_top_and_refresh_maintains_order(): void
    {
        $time1 = Carbon::parse('2026-11-15 10:00:00');
        $time2 = Carbon::parse('2026-11-15 10:10:00');

        $order1 = $this->createOrder('ORD-SEQ-1', 'User 1', $time1);
        $order2 = $this->createOrder('ORD-SEQ-2', 'User 2', $time2);

        $res1 = $this->actingAs($this->kasir)->get(route('fnb.queue.index'));
        $content1 = $res1->getContent();
        $this->assertTrue(strpos($content1, 'ORD-SEQ-2') < strpos($content1, 'ORD-SEQ-1'));

        // Add brand new order at later time
        $time3 = Carbon::parse('2026-11-15 10:20:00');
        $order3 = $this->createOrder('ORD-SEQ-3', 'User 3', $time3);

        $res2 = $this->actingAs($this->kasir)->get(route('fnb.queue.index'));
        $content2 = $res2->getContent();
        $pos1 = strpos($content2, 'ORD-SEQ-1');
        $pos2 = strpos($content2, 'ORD-SEQ-2');
        $pos3 = strpos($content2, 'ORD-SEQ-3');

        $this->assertTrue($pos3 < $pos2, 'Newest order 3 must be at the very top');
        $this->assertTrue($pos2 < $pos1, 'Order 2 must be above Order 1');

        // Repeating refresh / index fetch returns the exact same ordering
        $res3 = $this->actingAs($this->kasir)->get(route('fnb.queue.index'));
        $content3 = $res3->getContent();
        $this->assertSame(
            [strpos($content2, 'ORD-SEQ-3') < strpos($content2, 'ORD-SEQ-2'), strpos($content2, 'ORD-SEQ-2') < strpos($content2, 'ORD-SEQ-1')],
            [strpos($content3, 'ORD-SEQ-3') < strpos($content3, 'ORD-SEQ-2'), strpos($content3, 'ORD-SEQ-2') < strpos($content3, 'ORD-SEQ-1')]
        );
    }
}
