<?php

namespace Tests\Feature\Hardware;

use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\Transaction;
use App\Models\TvAlertEvent;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ConsoleAgentApiTest extends TestCase
{
    use DatabaseTransactions;

    protected Playstation $psCategory;
    protected Device $station;
    protected PhysicalDevice $console;
    protected string $plainToken;
    protected Transaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->psCategory = Playstation::create([
            'nama' => 'PlayStation 4 Pro',
            'harga' => 15000,
        ]);

        $this->station = Device::create([
            'nama' => 'PS 04 Test Station',
            'status' => 'Digunakan',
            'playstation_id' => $this->psCategory->id,
            'session_generation' => 1,
        ]);

        $this->console = PhysicalDevice::create([
            'name' => 'PS4 Pro Station 04',
            'category' => PhysicalDevice::CATEGORY_GAME_CONSOLE,
            'brand' => PhysicalDevice::BRAND_SONY,
            'model' => PhysicalDevice::MODEL_PS4_HEN,
            'ip_address' => '192.168.1.184',
            'is_active' => true,
        ]);

        $this->plainToken = $this->console->generateDeviceToken();

        StationDeviceMapping::create([
            'station_id' => $this->station->id,
            'physical_device_id' => $this->console->id,
            'mapping_role' => StationDeviceMapping::ROLE_GAME_CONSOLE,
            'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
        ]);

        $this->transaction = Transaction::create([
            'status' => 'user',
            'nama' => 'Gamer Station 04',
            'device_id' => $this->station->id,
            'playstation_id' => $this->psCategory->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'harga' => 15000,
            'total' => 15000,
            'billing_status' => Transaction::BILLING_RUNNING,
            'session_generation' => 1,
            'waktu_mulai' => '10:00',
            'started_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addHour(),
        ]);
    }

    /** @test */
    public function scenario_d_polling_requires_valid_token_authentication()
    {
        // 1. Missing token -> 401
        $resNoToken = $this->getJson('/api/console-agent/v1/events');
        $resNoToken->assertStatus(401);

        // 2. Invalid token -> 401
        $resInvalidToken = $this->withHeader('X-Device-Token', 'invalid_random_token')
            ->getJson('/api/console-agent/v1/events');
        $resInvalidToken->assertStatus(401);

        // 3. Inactive device -> 403
        $this->console->update(['is_active' => false]);
        $resInactive = $this->withHeader('Authorization', "Bearer {$this->plainToken}")
            ->getJson('/api/console-agent/v1/events');
        $resInactive->assertStatus(403);

        // 4. Valid token -> 200
        $this->console->update(['is_active' => true]);
        $resValid = $this->withHeader('Authorization', "Bearer {$this->plainToken}")
            ->getJson('/api/console-agent/v1/events');
        $resValid->assertStatus(200);
        $resValid->assertJson([
            'success' => true,
            'has_events' => false,
        ]);
    }

    /** @test */
    public function scenario_d_polling_dispatches_pending_event_and_updates_status_to_delivered()
    {
        // Create pending alert for this station
        $event = TvAlertEvent::create([
            'station_id' => $this->station->id,
            'station_name' => $this->station->nama,
            'transaction_id' => $this->transaction->id_transaksi,
            'physical_device_id' => $this->console->id,
            'target_channel' => 'CONSOLE',
            'event_type' => TvAlertEvent::TYPE_BILLING_ENDING_SOON,
            'command' => TvAlertEvent::CMD_SHOW_ALERT,
            'message_preset' => 'REMAINING_10_MIN',
            'message_title' => 'SISA WAKTU 10 MENIT',
            'message_body' => 'Waktu bermain tersisa 10 menit. Silakan hubungi kasir untuk memperpanjang waktu.',
            'display_duration_seconds' => 8,
            'control_mode' => 'REAL',
            'driver_type' => 'PS4_GOLDHEN',
            'session_generation' => 1,
            'status' => TvAlertEvent::STATUS_PENDING,
            'scheduled_at' => Carbon::now(),
        ]);

        $response = $this->withHeader('X-Device-Token', $this->plainToken)
            ->getJson('/api/console-agent/v1/events?station_id=' . $this->station->id);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'has_events' => true,
            'count' => 1,
            'event' => [
                'event_id' => $event->id,
                'station_id' => $this->station->id,
                'title' => 'SISA WAKTU 10 MENIT',
                'message' => 'Waktu bermain tersisa 10 menit. Silakan hubungi kasir untuk memperpanjang waktu.',
            ],
        ]);

        $event->refresh();
        $this->assertEquals(TvAlertEvent::STATUS_DELIVERED, $event->status);
        $this->assertNotNull($event->delivered_at);

        $this->console->refresh();
        $this->assertEquals(PhysicalDevice::CONNECTION_ONLINE, $this->console->connection_status);
        $this->assertNotNull($this->console->last_seen_at);
    }

    /** @test */
    public function scenario_e_agent_acknowledgement_transitions_event_to_completed()
    {
        $event = TvAlertEvent::create([
            'station_id' => $this->station->id,
            'station_name' => $this->station->nama,
            'transaction_id' => $this->transaction->id_transaksi,
            'physical_device_id' => $this->console->id,
            'target_channel' => 'CONSOLE',
            'event_type' => TvAlertEvent::TYPE_MANUAL_ALERT,
            'command' => TvAlertEvent::CMD_SHOW_ALERT,
            'message_preset' => 'CUSTOM',
            'message_title' => 'Pesan Admin',
            'message_body' => 'Silakan ke kasir sekarang',
            'control_mode' => 'REAL',
            'driver_type' => 'PS4_GOLDHEN',
            'session_generation' => 1,
            'status' => TvAlertEvent::STATUS_DELIVERED,
            'scheduled_at' => Carbon::now(),
            'delivered_at' => Carbon::now(),
        ]);

        $resAck = $this->withHeader('X-Device-Token', $this->plainToken)
            ->postJson("/api/console-agent/v1/events/{$event->id}/ack", [
                'status' => 'NOTIFICATION_API_CALLED',
            ]);

        $resAck->assertStatus(200);
        $resAck->assertJson([
            'success' => true,
            'status' => TvAlertEvent::STATUS_COMPLETED,
            'execution_status' => TvAlertEvent::STATUS_STATE_VERIFIED,
        ]);

        $event->refresh();
        $this->assertEquals(TvAlertEvent::STATUS_COMPLETED, $event->status);
        $this->assertEquals(TvAlertEvent::STATUS_STATE_VERIFIED, $event->execution_status);
        $this->assertNotNull($event->acked_at);
    }

    /** @test */
    public function scenario_e_agent_failure_reporting_records_failed_status()
    {
        $event = TvAlertEvent::create([
            'station_id' => $this->station->id,
            'station_name' => $this->station->nama,
            'transaction_id' => $this->transaction->id_transaksi,
            'physical_device_id' => $this->console->id,
            'target_channel' => 'CONSOLE',
            'event_type' => TvAlertEvent::TYPE_MANUAL_ALERT,
            'command' => TvAlertEvent::CMD_SHOW_ALERT,
            'status' => TvAlertEvent::STATUS_DELIVERED,
            'session_generation' => 1,
        ]);

        $resFail = $this->withHeader('X-Device-Token', $this->plainToken)
            ->postJson("/api/console-agent/v1/events/{$event->id}/ack", [
                'status' => 'FAILED',
                'error_message' => 'Native notification error -2147418113',
            ]);

        $resFail->assertStatus(200);
        $resFail->assertJson([
            'success' => false,
            'status' => TvAlertEvent::STATUS_FAILED,
            'error_message' => 'Native notification error -2147418113',
        ]);

        $event->refresh();
        $this->assertEquals(TvAlertEvent::STATUS_FAILED, $event->status);
        $this->assertEquals('AGENT_EXECUTION_FAILED', $event->result_code);
    }

    /** @test */
    public function heartbeat_updates_device_metadata_and_last_seen()
    {
        $resHeartbeat = $this->withHeader('Authorization', "Bearer {$this->plainToken}")
            ->postJson('/api/console-agent/v1/heartbeat', [
                'homebrew_type' => 'GOLDHEN',
                'firmware' => '9.00',
                'plugin_version' => '1.0.0',
            ]);

        $resHeartbeat->assertStatus(200);
        $resHeartbeat->assertJson([
            'success' => true,
            'status' => 'ONLINE',
        ]);

        $this->console->refresh();
        $this->assertEquals('ONLINE', $this->console->connection_status);
        $this->assertEquals('9.00', $this->console->firmware_version);
        $this->assertEquals('1.0.0', $this->console->homebrew_version);
        $this->assertEquals('GOLDHEN', $this->console->homebrew_type);
        $this->assertNotNull($this->console->last_seen_at);
    }
}
