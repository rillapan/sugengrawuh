<?php

namespace Tests\Feature\Hardware;

use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\Transaction;
use App\Models\TvAlertEvent;
use App\Models\User;
use App\Services\BillingService;
use App\Services\Hardware\ConsoleNotificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ConsoleNotificationIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected User $owner;
    protected User $kasir;
    protected Playstation $ps4Category;
    protected Playstation $billiardCategory;
    protected Device $psStation1;
    protected Device $psStation2;
    protected Device $billiardStation;
    protected PhysicalDevice $console1;
    protected PhysicalDevice $console2;
    protected BillingService $billingService;
    protected ConsoleNotificationService $consoleService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin Testing',
            'username' => 'admin_test_' . uniqid(),
            'email' => 'admin_test_' . uniqid() . '@pos.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $this->owner = User::create([
            'name' => 'Owner Testing',
            'username' => 'owner_test_' . uniqid(),
            'email' => 'owner_test_' . uniqid() . '@pos.test',
            'password' => bcrypt('password'),
            'role' => 'owner',
        ]);

        $this->kasir = User::create([
            'name' => 'Kasir Testing',
            'username' => 'kasir_test_' . uniqid(),
            'email' => 'kasir_test_' . uniqid() . '@pos.test',
            'password' => bcrypt('password'),
            'role' => 'kasir',
        ]);

        $this->ps4Category = Playstation::create([
            'nama' => 'PlayStation 4 VIP',
            'harga' => 15000,
        ]);

        $this->billiardCategory = Playstation::create([
            'nama' => 'Meja Biliar Standard',
            'harga' => 30000,
        ]);

        $this->psStation1 = Device::create([
            'nama' => 'PS 01',
            'status' => 'Tersedia',
            'playstation_id' => $this->ps4Category->id,
            'session_generation' => 1,
        ]);

        $this->psStation2 = Device::create([
            'nama' => 'PS 02',
            'status' => 'Tersedia',
            'playstation_id' => $this->ps4Category->id,
            'session_generation' => 1,
        ]);

        $this->billiardStation = Device::create([
            'nama' => 'Meja Biliar 01',
            'status' => 'Tersedia',
            'playstation_id' => $this->billiardCategory->id,
            'session_generation' => 1,
        ]);

        $this->console1 = PhysicalDevice::create([
            'name' => 'PS4 Console 01',
            'category' => PhysicalDevice::CATEGORY_GAME_CONSOLE,
            'brand' => PhysicalDevice::BRAND_SONY,
            'model' => PhysicalDevice::MODEL_PS4_HEN,
            'ip_address' => '192.168.1.191',
            'is_active' => true,
        ]);

        $this->console2 = PhysicalDevice::create([
            'name' => 'PS4 Console 02',
            'category' => PhysicalDevice::CATEGORY_GAME_CONSOLE,
            'brand' => PhysicalDevice::BRAND_SONY,
            'model' => PhysicalDevice::MODEL_PS4_HEN,
            'ip_address' => '192.168.1.192',
            'is_active' => true,
        ]);

        StationDeviceMapping::create([
            'station_id' => $this->psStation1->id,
            'physical_device_id' => $this->console1->id,
            'mapping_role' => StationDeviceMapping::ROLE_GAME_CONSOLE,
            'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
        ]);

        StationDeviceMapping::create([
            'station_id' => $this->psStation2->id,
            'physical_device_id' => $this->console2->id,
            'mapping_role' => StationDeviceMapping::ROLE_GAME_CONSOLE,
            'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
        ]);

        $this->billingService = app(BillingService::class);
        $this->consoleService = app(ConsoleNotificationService::class);
    }

    /** @test */
    public function scenario_g_station_mapping_isolation_ensures_ps01_alert_does_not_leak_to_ps02()
    {
        // Start running session on PS 01
        $tx = $this->billingService->startSession([
            'device_id' => $this->psStation1->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 3600,
        ], $this->admin->id);

        $response = $this->actingAs($this->admin)
            ->postJson("/admin/stations/{$this->psStation1->id}/console-notifications", [
                'preset' => 'REMAINING_10_MIN',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'station_id' => $this->psStation1->id,
                'preset' => 'REMAINING_10_MIN',
            ],
        ]);

        // Verify event was created strictly for console 1 and station 1
        $event = TvAlertEvent::where('station_id', $this->psStation1->id)->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertEquals($this->console1->id, $event->physical_device_id);

        // Verify no event exists for station 2
        $station2Events = TvAlertEvent::where('station_id', $this->psStation2->id)->count();
        $this->assertEquals(0, $station2Events);
    }

    /** @test */
    public function scenario_g_billiard_station_and_unmapped_station_are_strictly_rejected()
    {
        // 1. Billiard Station -> Rejected with invalid category
        $this->billingService->startSession([
            'device_id' => $this->billiardStation->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 3600,
        ], $this->admin->id);

        $resBilliard = $this->actingAs($this->admin)
            ->postJson("/admin/stations/{$this->billiardStation->id}/console-notifications", [
                'preset' => 'REMAINING_10_MIN',
            ]);

        $resBilliard->assertStatus(422);
        $resBilliard->assertJson([
            'error_code' => 'INVALID_STATION_CATEGORY',
        ]);

        // 2. Unmapped PS Station -> Rejected
        $unmappedStation = Device::create([
            'nama' => 'PS 99 Unmapped',
            'status' => 'Tersedia',
            'playstation_id' => $this->ps4Category->id,
            'session_generation' => 1,
        ]);

        $this->billingService->startSession([
            'device_id' => $unmappedStation->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 3600,
        ], $this->admin->id);

        $resUnmapped = $this->actingAs($this->admin)
            ->postJson("/admin/stations/{$unmappedStation->id}/console-notifications", [
                'preset' => 'REMAINING_10_MIN',
            ]);

        $resUnmapped->assertStatus(422);
        $this->assertStringContainsString('Game Console aktif', $resUnmapped->json('message'));
    }

    /** @test */
    public function scenario_h_rbac_enforcement_allows_admin_and_owner_but_forbids_kasir()
    {
        $this->billingService->startSession([
            'device_id' => $this->psStation1->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 3600,
        ], $this->admin->id);

        // 1. Admin -> 200
        $resAdmin = $this->actingAs($this->admin)
            ->postJson("/admin/stations/{$this->psStation1->id}/console-notifications", [
                'preset' => 'ALMOST_FINISHED',
            ]);
        $resAdmin->assertStatus(200);

        // 2. Owner -> 200
        $resOwner = $this->actingAs($this->owner)
            ->postJson("/admin/stations/{$this->psStation1->id}/console-notifications", [
                'preset' => 'LOWER_VOLUME',
            ]);
        $resOwner->assertStatus(200);

        // 3. Kasir -> 403 Forbidden
        $resKasir = $this->actingAs($this->kasir)
            ->postJson("/admin/stations/{$this->psStation1->id}/console-notifications", [
                'preset' => 'TIME_EXPIRED',
            ]);
        $resKasir->assertStatus(403);
    }

    /** @test */
    public function scenario_f_session_extension_and_session_stop_cancels_pending_console_alerts()
    {
        $tx = $this->billingService->startSession([
            'device_id' => $this->psStation1->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 3600,
        ], $this->admin->id);

        // Queue pending console alert
        $this->actingAs($this->admin)
            ->postJson("/admin/stations/{$this->psStation1->id}/console-notifications", [
                'preset' => 'REMAINING_10_MIN',
            ]);

        $pendingEvent = TvAlertEvent::where('station_id', $this->psStation1->id)
            ->where('status', TvAlertEvent::STATUS_PENDING)
            ->first();
        $this->assertNotNull($pendingEvent);

        // Extend session -> pending alert MUST be cancelled
        $this->billingService->extendSession($tx->id_transaksi, 1, false);

        $pendingEvent->refresh();
        $this->assertEquals(TvAlertEvent::STATUS_CANCELLED, $pendingEvent->status);
        $this->assertStringContainsString('extended', strtolower($pendingEvent->error_message));

        // Queue another alert and stop session -> MUST be cancelled
        $this->actingAs($this->admin)
            ->postJson("/admin/stations/{$this->psStation1->id}/console-notifications", [
                'preset' => 'ALMOST_FINISHED',
            ]);

        $secondEvent = TvAlertEvent::where('station_id', $this->psStation1->id)
            ->where('status', TvAlertEvent::STATUS_PENDING)
            ->first();
        $this->assertNotNull($secondEvent);

        $this->billingService->stopSession($tx->id_transaksi, 'manual_stop');

        $secondEvent->refresh();
        $this->assertEquals(TvAlertEvent::STATUS_CANCELLED, $secondEvent->status);
        $this->assertStringContainsString('stopped', strtolower($secondEvent->error_message));
    }

    /** @test */
    public function scenario_i_hardware_safety_guard_ensures_mock_mode_default_and_blocks_physical_execution()
    {
        $this->assertEquals('mock', config('hardware.console_notifications_transport'));
        $this->assertFalse(config('hardware.console_real_execution_enabled'));

        // Console status endpoint returns safe MOCK mode
        $resStatus = $this->actingAs($this->admin)
            ->getJson("/admin/stations/{$this->psStation1->id}/console-status");

        $resStatus->assertStatus(200);
        $resStatus->assertJson([
            'success' => true,
            'data' => [
                'has_console' => true,
                'name' => 'PS4 Console 01',
            ],
        ]);
    }
}
