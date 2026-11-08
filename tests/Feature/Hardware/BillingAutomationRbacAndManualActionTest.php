<?php

namespace Tests\Feature\Hardware;

use App\Models\AutomationIntent;
use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingAutomationRbacAndManualActionTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected User $kasir;
    protected User $owner;
    protected Playstation $playstation;
    protected Device $station;
    protected PhysicalDevice $physicalDevice;
    protected StationDeviceMapping $mapping;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertEquals('kasir_ps_test', DB::connection()->getDatabaseName(), 'Must run on kasir_ps_test');
        Config::set('hardware.driver', 'mock');
        Config::set('hardware.simulation_enabled', true);
        Config::set('hardware.automation_enabled', true);

        $this->admin = User::firstOrCreate(
            ['email' => 'admin_rbac@test.local'],
            ['name' => 'Admin Rbac', 'role' => 'admin', 'password' => bcrypt('secret123')]
        );

        $this->kasir = User::firstOrCreate(
            ['email' => 'kasir_rbac@test.local'],
            ['name' => 'Kasir Rbac', 'role' => 'kasir', 'password' => bcrypt('secret123')]
        );

        $this->owner = User::firstOrCreate(
            ['email' => 'owner_rbac@test.local'],
            ['name' => 'Owner Rbac', 'role' => 'owner', 'password' => bcrypt('secret123')]
        );

        $this->playstation = Playstation::firstOrCreate(
            ['nama' => 'Billiard Standard RBAC Table'],
            ['harga' => 30000]
        );

        $this->station = Device::firstOrCreate(
            ['nama' => 'Meja Biliar RBAC 01'],
            ['playstation_id' => $this->playstation->id, 'status' => 'Tersedia', 'session_generation' => 1]
        );
        $this->station->update(['status' => 'Tersedia', 'session_generation' => 1]);

        $this->physicalDevice = PhysicalDevice::firstOrCreate(
            ['mac_address' => 'AA:BB:CC:DD:EE:99'],
            [
                'name' => 'Shelly 1PM RBAC Test',
                'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
                'brand' => PhysicalDevice::BRAND_SHELLY,
                'model' => 'Shelly 1PM Mini Gen3',
                'ip_address' => '192.168.1.199',
                'is_active' => true,
                'connection_status' => 'ONLINE',
                'verified_capabilities' => ['SWITCH_ON', 'SWITCH_OFF', 'GET_STATUS'],
            ]
        );

        $this->mapping = StationDeviceMapping::firstOrCreate(
            [
                'station_id' => $this->station->id,
                'physical_device_id' => $this->physicalDevice->id,
            ],
            [
                'mapping_role' => StationDeviceMapping::ROLE_LIGHT_RELAY,
                'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
                'created_by' => $this->admin->id,
            ]
        );
        $this->mapping->update(['mapping_status' => StationDeviceMapping::STATUS_ACTIVE]);
    }

    public function test_kasir_can_view_automation_status_and_sensitive_credentials_are_masked()
    {
        $this->actingAs($this->kasir);

        $response = $this->getJson("/api/stations/{$this->station->id}/automation-status");
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertArrayHasKey('station_id', $data);
        $this->assertArrayHasKey('station_name', $data);
        $this->assertArrayHasKey('hardware_state', $data);
        $this->assertArrayHasKey('hardware_badge', $data);

        // Security assertion: Sensitive network parameters MUST NOT be leaked to cashier
        $this->assertArrayNotHasKey('ip_address', $data);
        $this->assertArrayNotHasKey('mac_address', $data);
        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('token', $data);
        $this->assertArrayNotHasKey('pairing_payload', $data);
    }

    public function test_kasir_can_trigger_manual_retry_for_failed_intent()
    {
        $this->actingAs($this->kasir);

        // Create a failed intent
        $intent = AutomationIntent::create([
            'station_id' => $this->station->id,
            'physical_device_id' => $this->physicalDevice->id,
            'event_type' => AutomationIntent::EVENT_BILLING_STARTED,
            'desired_state' => AutomationIntent::STATE_ON,
            'target_channel' => 0,
            'session_generation' => 1,
            'idempotency_key' => 'MANUAL-RETRY-TEST-001',
            'status' => AutomationIntent::STATUS_FAILED,
            'scheduled_at' => Carbon::now()->subMinutes(5),
            'attempts' => 4,
            'max_attempts' => 4,
            'last_error_category' => 'NETWORK_TIMEOUT',
            'last_error_message' => 'Simulated device timeout',
        ]);

        $response = $this->postJson("/api/stations/{$this->station->id}/automation/manual-action", [
            'action' => 'RETRY_LAST_INTENT',
            'reason' => 'Kasir retried after checking hardware plug',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);

        $intent->refresh();
        $this->assertEquals(AutomationIntent::STATUS_PENDING, $intent->status);
        $this->assertEquals(0, $intent->attempts);
        $this->assertNull($intent->next_retry_at);
        $this->assertStringContainsString('Manual retry requested by user', $intent->last_error_message);
    }

    public function test_kasir_cannot_turn_on_relay_freely_without_active_session()
    {
        $this->actingAs($this->kasir);

        // Attempt to pass arbitrary action to switch relay ON freely
        $response = $this->postJson("/api/stations/{$this->station->id}/automation/manual-action", [
            'action' => 'OVERRIDE_ON',
            'reason' => 'Unauthorized free light switch attempt',
        ]);

        // Validation rule strictly forbids OVERRIDE_ON (in:RETRY_LAST_INTENT,OVERRIDE_OFF)
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['action']);
    }

    public function test_kasir_cannot_override_off_while_billing_session_is_running()
    {
        $this->actingAs($this->kasir);

        // Create an active RUNNING billing transaction on this station
        $tx = Transaction::create([
            'device_id' => $this->station->id,
            'user_id' => $this->kasir->id,
            'nama' => 'Active Customer',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'waktu_mulai' => Carbon::now()->format('H:i'),
            'started_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addHour(),
            'billing_status' => Transaction::BILLING_RUNNING,
            'status_transaksi' => 'sukses',
            'session_generation' => 1,
            'total' => 30000,
            'harga' => 30000,
            'status' => 'user',
        ]);

        $response = $this->postJson("/api/stations/{$this->station->id}/automation/manual-action", [
            'action' => 'OVERRIDE_OFF',
            'reason' => 'Accidental override OFF attempt during active play',
        ]);

        $response->assertStatus(400);
        $this->assertStringContainsString('Sesi billing masih berjalan aktif', $response->json('message'));
    }

    public function test_manual_action_rejects_nonexistent_station()
    {
        $this->actingAs($this->kasir);

        $response = $this->postJson("/api/stations/999999/automation/manual-action", [
            'action' => 'RETRY_LAST_INTENT',
        ]);

        $response->assertStatus(404);
    }

    public function test_owner_is_forbidden_from_technical_hardware_diagnostics()
    {
        $this->actingAs($this->owner);

        // Owner cannot access technical diagnostics endpoints reserved for Admin
        $response = $this->get('/admin/hardware/diagnostics');
        $response->assertStatus(403);
    }

    public function test_admin_has_technical_diagnostics_access()
    {
        $this->actingAs($this->admin);

        $response = $this->get('/admin/hardware/diagnostics');
        $response->assertStatus(200);
    }
}
