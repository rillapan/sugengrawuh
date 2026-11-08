<?php

namespace Tests\Feature;

use App\Models\AutomationIntent;
use App\Models\Device;
use App\Models\HardwarePowerCommandLog;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Hardware\Automation\AutomationFencingGuard;
use App\Services\Hardware\Drivers\SamsungTvDriver;
use App\Services\Hardware\Drivers\ShellyRelayDriver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

class HardwareManualPowerControlTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $owner;
    protected User $kasir;
    protected Device $billiardStation;
    protected Device $psStation;
    protected Playstation $billiardService;
    protected Playstation $psService;
    protected PhysicalDevice $shellyRelay;
    protected PhysicalDevice $samsungTv;
    protected StationDeviceMapping $billiardMapping;
    protected StationDeviceMapping $tvMapping;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure mock driver mode for safe testing
        Config::set('hardware.driver', 'mock');
        Config::set('hardware.real_execution_enabled', false);
        Config::set('hardware.simulation_enabled', true);

        $this->admin = User::factory()->create([
            'name' => 'Admin Power',
            'role' => 'admin',
            'email' => 'admin_pwr@sugengrawuh.test',
        ]);

        $this->owner = User::factory()->create([
            'name' => 'Owner Power',
            'role' => 'owner',
            'email' => 'owner_pwr@sugengrawuh.test',
        ]);

        $this->kasir = User::factory()->create([
            'name' => 'Kasir Power',
            'role' => 'kasir',
            'email' => 'kasir_pwr@sugengrawuh.test',
        ]);

        // Billiard Station & Service
        $this->billiardService = Playstation::create([
            'nama' => 'BILLIARD 9-FEET',
            'harga' => 30000,
        ]);

        $this->billiardStation = Device::create([
            'nama' => 'Meja 1 - Billiard',
            'status' => 'Tersedia',
            'playstation_id' => $this->billiardService->id,
            'session_generation' => 1,
            'last_hardware_state' => 'STATE_OFF',
        ]);
        $this->billiardStation->playstations()->attach($this->billiardService->id);

        // PS Station & Service
        $this->psService = Playstation::create([
            'nama' => 'PS5 VIP',
            'harga' => 25000,
        ]);

        $this->psStation = Device::create([
            'nama' => 'PS5 VIP 01',
            'status' => 'Tersedia',
            'playstation_id' => $this->psService->id,
            'session_generation' => 1,
            'last_hardware_state' => 'STATE_OFF',
        ]);
        $this->psStation->playstations()->attach($this->psService->id);

        // Physical Devices
        $this->shellyRelay = PhysicalDevice::create([
            'name' => 'Shelly Relay Meja 1',
            'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
            'brand' => PhysicalDevice::BRAND_SHELLY,
            'model' => 'Shelly 1PM Mini Gen3',
            'mac_address' => 'AA:BB:CC:11:22:33',
            'ip_address' => '192.168.200.241',
            'driver_type' => 'shelly_plus',
            'device_identifier' => 'shelly-billiard-01',
            'connection_status' => PhysicalDevice::CONNECTION_ONLINE,
            'is_active' => true,
            'verified_capabilities' => ['SWITCH_ON', 'SWITCH_OFF', 'STATUS'],
        ]);

        $this->samsungTv = PhysicalDevice::create([
            'name' => 'Samsung TV Meja 1',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => 'CU7000 43',
            'mac_address' => 'AA:BB:CC:44:55:66',
            'ip_address' => '192.168.200.50',
            'driver_type' => 'samsung_tv',
            'device_identifier' => 'tv-billiard-01',
            'connection_status' => PhysicalDevice::CONNECTION_ONLINE,
            'is_active' => true,
            'verified_capabilities' => ['STANDBY'], // Note: POWER_ON not verified
        ]);

        // Mappings
        $this->billiardMapping = StationDeviceMapping::create([
            'station_id' => $this->billiardStation->id,
            'physical_device_id' => $this->shellyRelay->id,
            'mapping_role' => StationDeviceMapping::ROLE_LIGHT_RELAY,
            'channel' => 0,
            'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
            'is_primary' => true,
        ]);

        $this->tvMapping = StationDeviceMapping::create([
            'station_id' => $this->psStation->id,
            'physical_device_id' => $this->samsungTv->id,
            'mapping_role' => StationDeviceMapping::ROLE_DISPLAY_TV,
            'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
            'is_primary' => true,
        ]);
    }

    /**
     * Test 01: Unauthenticated guest cannot control power or view status.
     */
    public function test_guest_cannot_control_power_or_view_status(): void
    {
        $response = $this->postJson("/admin/stations/{$this->billiardStation->id}/hardware/power", [
            'physical_device_id' => $this->shellyRelay->id,
            'desired_state' => 'ON',
        ]);
        $response->assertStatus(401);

        $statusRes = $this->getJson("/admin/stations/{$this->billiardStation->id}/hardware/status");
        $statusRes->assertStatus(401);
    }

    /**
     * Test 02: Kasir is strictly forbidden from manual power control (HTTP 403).
     */
    public function test_cashier_is_strictly_forbidden_from_power_control(): void
    {
        $response = $this->actingAs($this->kasir)->postJson("/admin/stations/{$this->billiardStation->id}/hardware/power", [
            'physical_device_id' => $this->shellyRelay->id,
            'desired_state' => 'ON',
        ]);

        $response->assertStatus(403);
    }

    /**
     * Test 03: Admin can turn ON and OFF station in 'Tersedia' status.
     */
    public function test_admin_can_turn_on_and_off_station_in_tersedia_status(): void
    {
        // 1. Turn ON
        $responseOn = $this->actingAs($this->admin)->postJson("/admin/stations/{$this->billiardStation->id}/hardware/power", [
            'physical_device_id' => $this->shellyRelay->id,
            'desired_state' => 'ON',
        ]);

        $responseOn->assertStatus(200);
        $responseOn->assertJson([
            'success' => true,
            'desired_state' => 'ON',
            'reported_state' => 'ON',
        ]);

        $this->billiardStation->refresh();
        $this->assertNotNull($this->billiardStation->last_manual_power_at);
        $this->assertEquals('STATE_ON', $this->billiardStation->last_hardware_state);

        // Verify audit log created
        $this->assertDatabaseHas('hardware_power_command_logs', [
            'station_id' => $this->billiardStation->id,
            'physical_device_id' => $this->shellyRelay->id,
            'user_id' => $this->admin->id,
            'desired_state' => 'ON',
            'reported_state' => 'ON',
            'execution_status' => 'SUCCESS',
        ]);

        // 2. Turn OFF
        $responseOff = $this->actingAs($this->admin)->postJson("/admin/stations/{$this->billiardStation->id}/hardware/power", [
            'physical_device_id' => $this->shellyRelay->id,
            'desired_state' => 'OFF',
        ]);

        $responseOff->assertStatus(200);
        $responseOff->assertJson([
            'success' => true,
            'desired_state' => 'OFF',
            'reported_state' => 'OFF',
        ]);

        $this->billiardStation->refresh();
        $this->assertEquals('STATE_OFF', $this->billiardStation->last_hardware_state);
    }

    /**
     * Test 04: Owner has full authorization for power control.
     */
    public function test_owner_can_control_power(): void
    {
        $response = $this->actingAs($this->owner)->postJson("/admin/stations/{$this->billiardStation->id}/hardware/power", [
            'physical_device_id' => $this->shellyRelay->id,
            'desired_state' => 'ON',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'reported_state' => 'ON',
        ]);
    }

    /**
     * Test 05: Manual power control on 'Digunakan' station DOES NOT alter session_generation, timer, or billing.
     */
    public function test_manual_power_on_station_in_digunakan_status_does_not_mutate_session_generation_or_timer(): void
    {
        // Setup active transaction
        $this->billiardStation->update([
            'status' => 'Digunakan',
            'session_generation' => 5,
        ]);

        $startedAt = Carbon::now()->subMinutes(10);
        $transaction = Transaction::create([
            'device_id' => $this->billiardStation->id,
            'status' => 'user',
            'tipe_transaksi' => 'prepaid',
            'harga' => 30000,
            'waktu_mulai' => $startedAt->toDateTimeString(),
            'waktu_selesai' => $startedAt->copy()->addMinutes(60)->toDateTimeString(),
            'jam_main' => 1,
            'duration_seconds' => 3600,
            'total' => 30000,
            'total_keseluruhan' => 30000,
        ]);

        // Execute manual power OFF
        $response = $this->actingAs($this->admin)->postJson("/admin/stations/{$this->billiardStation->id}/hardware/power", [
            'physical_device_id' => $this->shellyRelay->id,
            'desired_state' => 'OFF',
        ]);

        $response->assertStatus(200);

        // Verify Station invariants
        $this->billiardStation->refresh();
        $this->assertEquals('Digunakan', $this->billiardStation->status);
        $this->assertEquals(5, $this->billiardStation->session_generation, 'session_generation MUST NOT be altered by manual power commands');
        $this->assertEquals('STATE_OFF', $this->billiardStation->last_hardware_state);

        // Verify Transaction invariants
        $transaction->refresh();
        $this->assertEquals(3600, $transaction->duration_seconds);
        $this->assertEquals(30000, $transaction->total);
        $this->assertEquals('user', $transaction->status);
    }

    /**
     * Test 06: Invalid mapping or inactive physical device returns 422.
     */
    public function test_invalid_mapping_or_inactive_physical_device_returns_422(): void
    {
        // 1. Unmapped device
        $unmappedDevice = PhysicalDevice::create([
            'name' => 'Unmapped Relay',
            'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
            'brand' => PhysicalDevice::BRAND_SHELLY,
            'mac_address' => '11:22:33:44:55:66',
            'ip_address' => '192.168.1.99',
            'driver_type' => 'shelly_plus',
            'device_identifier' => 'unmapped-01',
            'is_active' => true,
        ]);

        $resUnmapped = $this->actingAs($this->admin)->postJson("/admin/stations/{$this->billiardStation->id}/hardware/power", [
            'physical_device_id' => $unmappedDevice->id,
            'desired_state' => 'ON',
        ]);
        $resUnmapped->assertStatus(422);
        $resUnmapped->assertJson(['result_code' => 'INVALID_MAPPING']);

        // 2. Inactive mapped device
        $this->shellyRelay->update(['is_active' => false]);
        $resInactive = $this->actingAs($this->admin)->postJson("/admin/stations/{$this->billiardStation->id}/hardware/power", [
            'physical_device_id' => $this->shellyRelay->id,
            'desired_state' => 'ON',
        ]);
        $resInactive->assertStatus(422);
        $resInactive->assertJson(['result_code' => 'DEVICE_INACTIVE']);
    }

    /**
     * Test 07: Invalid desired state returns 422.
     */
    public function test_invalid_desired_state_returns_422(): void
    {
        $response = $this->actingAs($this->admin)->postJson("/admin/stations/{$this->billiardStation->id}/hardware/power", [
            'physical_device_id' => $this->shellyRelay->id,
            'desired_state' => 'REBOOT',
        ]);
        $response->assertStatus(422);
    }

    /**
     * Test 08: Idempotency token prevents duplicate execution within cache window.
     */
    public function test_idempotency_prevents_duplicate_driver_execution(): void
    {
        $idempotencyKey = 'test_idempotency_' . Str::random(10);

        // First call
        $res1 = $this->actingAs($this->admin)->postJson("/admin/stations/{$this->billiardStation->id}/hardware/power", [
            'physical_device_id' => $this->shellyRelay->id,
            'desired_state' => 'ON',
            'idempotency_key' => $idempotencyKey,
        ]);
        $res1->assertStatus(200);

        // Immediate duplicate call with identical key
        $res2 = $this->actingAs($this->admin)->postJson("/admin/stations/{$this->billiardStation->id}/hardware/power", [
            'physical_device_id' => $this->shellyRelay->id,
            'desired_state' => 'ON',
            'idempotency_key' => $idempotencyKey,
        ]);
        $res2->assertStatus(200);
        $this->assertStringContainsString('Idempotent response', (string) $res2->json('message'));
    }

    /**
     * Test 09: Newer manual power override marks older pending automated intent as STALE.
     */
    public function test_newer_manual_override_marks_older_pending_intent_as_stale(): void
    {
        // 1. Create older automated intent
        $intentTime = Carbon::now()->subSeconds(30);
        $intent = AutomationIntent::create([
            'station_id' => $this->billiardStation->id,
            'physical_device_id' => $this->shellyRelay->id,
            'station_device_mapping_id' => $this->billiardMapping->id,
            'session_generation' => 1,
            'event_type' => 'BILLING_SESSION_STARTED',
            'desired_state' => 'ON',
            'target_state' => 'ON',
            'idempotency_key' => 'intent_' . Str::random(12),
            'status' => AutomationIntent::STATUS_PENDING,
            'scheduled_at' => $intentTime,
            'payload' => ['session_generation' => 1],
        ]);

        // 2. Execute manual power override (now > scheduled_at)
        $this->actingAs($this->admin)->postJson("/admin/stations/{$this->billiardStation->id}/hardware/power", [
            'physical_device_id' => $this->shellyRelay->id,
            'desired_state' => 'OFF',
        ]);

        $this->billiardStation->refresh();
        $this->assertNotNull($this->billiardStation->last_manual_power_at);

        // 3. AutomationFencingGuard validates older intent
        $fencingGuard = app(AutomationFencingGuard::class);
        $decision = $fencingGuard->validateFencing($intent);

        $this->assertFalse($decision['valid']);
        $this->assertEquals(AutomationIntent::STATUS_STALE, $decision['terminal_status']);
        $this->assertStringContainsString('superseded by newer manual power override', $decision['reason']);
    }

    /**
     * Test 10: Terminal stop/expiry intent executed AFTER manual override is safe and NOT stale.
     */
    public function test_terminal_stop_intent_after_manual_override_is_safely_executed(): void
    {
        // 1. Execute manual power command first
        $this->actingAs($this->admin)->postJson("/admin/stations/{$this->billiardStation->id}/hardware/power", [
            'physical_device_id' => $this->shellyRelay->id,
            'desired_state' => 'ON',
        ]);

        $this->billiardStation->refresh();

        // 2. Later stop intent is scheduled
        $stopIntent = AutomationIntent::create([
            'station_id' => $this->billiardStation->id,
            'physical_device_id' => $this->shellyRelay->id,
            'station_device_mapping_id' => $this->billiardMapping->id,
            'session_generation' => 1,
            'event_type' => AutomationIntent::EVENT_BILLING_STOPPED,
            'desired_state' => 'OFF',
            'target_state' => 'OFF',
            'idempotency_key' => 'intent_' . Str::random(12),
            'status' => AutomationIntent::STATUS_PENDING,
            'scheduled_at' => Carbon::now()->addSeconds(1),
            'payload' => ['session_generation' => 1],
        ]);

        $fencingGuard = app(AutomationFencingGuard::class);
        $decision = $fencingGuard->validateFencing($stopIntent);

        $this->assertTrue($decision['valid'], 'Later stop/expiry safety actions MUST remain executable');
    }

    /**
     * Test 11: Samsung TV POWER_ON returns UNSUPPORTED_CAPABILITY when capability is unverified.
     */
    public function test_samsung_tv_power_on_returns_unsupported_when_not_in_verified_capabilities(): void
    {
        // Samsung TV only has STANDBY in verified_capabilities in setUp()
        $response = $this->actingAs($this->admin)->postJson("/admin/stations/{$this->psStation->id}/hardware/power", [
            'physical_device_id' => $this->samsungTv->id,
            'desired_state' => 'ON',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'reported_state' => 'UNSUPPORTED',
            'result_code' => 'UNSUPPORTED_CAPABILITY',
        ]);
    }

    /**
     * Test 12: Device index view renders manual power widget with invariant visual components.
     */
    public function test_device_index_blade_renders_power_button_widget(): void
    {
        $response = $this->actingAs($this->admin)->get('/device');

        $response->assertStatus(200);
        $response->assertSee('manual-power-widget-container');
        $response->assertSee('btn-hardware-power');
        $response->assertSee('power-outer-ring');
        $response->assertSee('power-inner-circle');
        $response->assertSee('fa-power-off');
        $response->assertSee('power-status-box');
        $response->assertSee('power-status-text');
        $response->assertSee('confirmPowerOffModal');
    }

    /**
     * Test 13: Hardware status endpoint returns correct reported state.
     */
    public function test_hardware_status_endpoint_returns_correct_data(): void
    {
        $response = $this->actingAs($this->admin)->getJson("/admin/stations/{$this->billiardStation->id}/hardware/status?physical_device_id={$this->shellyRelay->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'device_name' => $this->shellyRelay->name,
            'connection_status' => 'ONLINE',
        ]);
    }
}
