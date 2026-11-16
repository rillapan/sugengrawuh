<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Playstation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StationCardBackgroundTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin.test@billing.test',
        ]);
    }

    /** @test */
    public function station_cards_render_correct_background_classes_based_on_category(): void
    {
        $psCategory = Playstation::create(['nama' => 'PS5', 'harga' => 15000]);
        $billiardPagi = Playstation::create(['nama' => 'BILLIARD pagi', 'harga' => 20000]);
        $billiardSore = Playstation::create(['nama' => 'BILLIARD sore', 'harga' => 25000]);
        $otherCategory = Playstation::create(['nama' => 'RACING SIMULATOR', 'harga' => 50000]);

        // 1. PlayStation Station (Single category)
        $psDevice = Device::create([
            'nama' => 'TV 1 PS5',
            'playstation_id' => $psCategory->id,
            'status' => 'Tersedia',
        ]);
        $psDevice->playstations()->attach($psCategory->id);

        // 2. Billiard Station (Multiple categories: pagi + sore)
        $billiardDevice = Device::create([
            'nama' => 'Meja 1 Billiard',
            'playstation_id' => $billiardPagi->id,
            'status' => 'Tersedia',
        ]);
        $billiardDevice->playstations()->attach([$billiardPagi->id, $billiardSore->id]);

        // 3. Unrecognized/Other category
        $otherDevice = Device::create([
            'nama' => 'Simulator 1',
            'playstation_id' => $otherCategory->id,
            'status' => 'Tersedia',
        ]);
        $otherDevice->playstations()->attach($otherCategory->id);

        $response = $this->actingAs($this->admin)->get(route('transaction.index'));
        $response->assertStatus(200);

        $content = $response->getContent();

        // Check CSS definitions
        $this->assertStringContainsString('.pos-station-card.has-bg-ps::before', $content);
        $this->assertStringContainsString('.pos-station-card.has-bg-billiard::before', $content);
        $this->assertStringContainsString('opacity: 0.6;', $content);
        $this->assertStringContainsString('pointer-events: none;', $content);
        $this->assertStringContainsString('background-size: contain;', $content);
        $this->assertStringContainsString('background-position: center', $content);
        $this->assertStringContainsString('background-repeat: no-repeat;', $content);
        $this->assertStringContainsString('img/bg-ps.webp', $content);
        $this->assertStringContainsString('img/bg-billard.png', $content);

        // Check PlayStation card has has-bg-ps class
        $this->assertMatchesRegularExpression(
            '/<article[^>]*id="station-card-' . $psDevice->id . '"[^>]*class="[^"]*has-bg-ps[^"]*"|<article[^>]*class="[^"]*has-bg-ps[^"]*"[^>]*id="station-card-' . $psDevice->id . '"/',
            $content,
            'Station PS should have has-bg-ps class.'
        );

        // Check Billiard card has has-bg-billiard class
        $this->assertMatchesRegularExpression(
            '/<article[^>]*id="station-card-' . $billiardDevice->id . '"[^>]*class="[^"]*has-bg-billiard[^"]*"|<article[^>]*class="[^"]*has-bg-billiard[^"]*"[^>]*id="station-card-' . $billiardDevice->id . '"/',
            $content,
            'Station Billiard should have has-bg-billiard class.'
        );

        // Check Unknown category card does NOT have background classes
        $this->assertDoesNotMatchRegularExpression(
            '/<article[^>]*id="station-card-' . $otherDevice->id . '"[^>]*class="[^"]*(has-bg-ps|has-bg-billiard)[^"]*"|<article[^>]*class="[^"]*(has-bg-ps|has-bg-billiard)[^"]*"[^>]*id="station-card-' . $otherDevice->id . '"/',
            $content,
            'Station with unrecognized category should not have background class.'
        );
    }

    /** @test */
    public function station_card_background_persists_across_all_statuses(): void
    {
        $billiardPagi = Playstation::create(['nama' => 'BILLIARD pagi', 'harga' => 20000]);

        $billiardDevice = Device::create([
            'nama' => 'Meja 1 Billiard',
            'playstation_id' => $billiardPagi->id,
            'status' => 'Digunakan',
        ]);
        $billiardDevice->playstations()->attach($billiardPagi->id);

        // Create active transaction (BERMAIN)
        $tx = Transaction::create([
            'id_transaksi' => 'TX-TEST-001',
            'device_id' => $billiardDevice->id,
            'playstation_id' => $billiardPagi->id,
            'user_id' => $this->admin->id,
            'nama' => 'Pelanggan Test',
            'tipe_transaksi' => 'postpaid',
            'status' => 'aktif',
            'status_transaksi' => 'berjalan',
            'payment_status' => 'unpaid',
            'harga' => 20000,
            'total' => 20000,
            'waktu_mulai' => now()->format('H:i'),
        ]);

        $response = $this->actingAs($this->admin)->get(route('transaction.index'));
        $response->assertStatus(200);

        $content = $response->getContent();

        // Must still have has-bg-billiard when status is Digunakan/Bermain
        $this->assertMatchesRegularExpression(
            '/<article[^>]*class="[^"]*pos-station-card[^"]*is-used[^"]*has-bg-billiard[^"]*"[^>]*id="station-card-' . $billiardDevice->id . '"|<article[^>]*id="station-card-' . $billiardDevice->id . '"[^>]*class="[^"]*pos-station-card[^"]*is-used[^"]*has-bg-billiard[^"]*"/',
            $content,
            'Station card should maintain has-bg-billiard when in use (BERMAIN).'
        );
    }
}
