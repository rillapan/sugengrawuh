<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Playstation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class KategoriPermainanTest extends TestCase
{
    use DatabaseTransactions;

    protected $admin;
    protected $kasir;
    protected $owner;
    protected $playstation;
    protected $billiard;
    protected $psDevice;
    protected $billiardDevice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin_kategori_' . uniqid() . '@billing.test'
        ]);

        $this->kasir = User::factory()->create([
            'role' => 'kasir',
            'email' => 'kasir_kategori_' . uniqid() . '@billing.test'
        ]);

        $this->owner = User::factory()->create([
            'role' => 'owner',
            'email' => 'owner_kategori_' . uniqid() . '@billing.test'
        ]);

        // Category 1: PlayStation
        $this->playstation = Playstation::create([
            'nama' => 'PS5 Ultimate',
            'harga' => 15000
        ]);

        // Category 2: Billiard
        $this->billiard = Playstation::create([
            'nama' => 'Meja Biliar 9ft',
            'harga' => 35000
        ]);

        // PS Station
        $this->psDevice = Device::create([
            'nama' => 'Station-PS5-01',
            'playstation_id' => $this->playstation->id,
            'status' => 'Tersedia'
        ]);
        $this->psDevice->playstations()->attach($this->playstation->id);

        // Billiard Station
        $this->billiardDevice = Device::create([
            'nama' => 'Meja-Biliar-01',
            'playstation_id' => $this->billiard->id,
            'status' => 'Tersedia'
        ]);
        $this->billiardDevice->playstations()->attach($this->billiard->id);
    }

    /** @test */
    public function test_kategori_permainan_index_is_accessible_by_admin_and_has_required_ui_elements()
    {
        $this->actingAs($this->admin);

        $response = $this->get('/kategori-permainan');

        $response->assertStatus(200);
        $response->assertViewIs('kategori-permainan.index');
        $response->assertSee('Kategori Permainan');
        $response->assertSee('Daftar Kategori Permainan');
        $response->assertSee('Tambah Kategori');
        $response->assertSee('Nama Permainan');
        $response->assertSee('Harga Dasar');
        $response->assertSee('PS5 Ultimate');
        $response->assertSee('Meja Biliar 9ft');
    }

    /** @test */
    public function test_legacy_get_playstation_url_redirects_to_kategori_permainan()
    {
        $this->actingAs($this->admin);

        $response = $this->get('/playstation');

        $response->assertStatus(302);
        $response->assertRedirect('/kategori-permainan');
    }

    /** @test */
    public function test_rbac_unauthenticated_and_kasir_access_to_kategori_permainan()
    {
        // Unauthenticated access redirects to login
        $responseGuest = $this->get('/kategori-permainan');
        $responseGuest->assertRedirect('/login');

        // Kasir access is forbidden (403)
        $this->actingAs($this->kasir);
        $responseKasir = $this->get('/kategori-permainan');
        $responseKasir->assertStatus(403);
    }

    /** @test */
    public function test_kategori_permainan_create_form_displays_proper_labels()
    {
        $this->actingAs($this->admin);

        $response = $this->get(route('kategori-permainan.create'));

        $response->assertStatus(200);
        $response->assertViewIs('kategori-permainan.create');
        $response->assertSee('Tambah Kategori Permainan');
        $response->assertSee('Nama Permainan');
        $response->assertSee('Harga Dasar');
    }

    /** @test */
    public function test_kategori_permainan_store_creates_record_and_redirects_with_notification()
    {
        $this->actingAs($this->admin);

        $response = $this->post(route('kategori-permainan.store'), [
            'nama' => 'Nintendo Switch OLED',
            'harga' => 12000,
        ]);

        $response->assertStatus(302);
        $response->assertRedirect(route('kategori-permainan.index'));
        $response->assertSessionHas('success', 'Data kategori permainan berhasil ditambahkan.');

        $this->assertDatabaseHas('playstations', [
            'nama' => 'Nintendo Switch OLED',
            'harga' => 12000,
        ]);
    }

    /** @test */
    public function test_kategori_permainan_store_validation_errors()
    {
        $this->actingAs($this->admin);

        $response = $this->post(route('kategori-permainan.store'), [
            'nama' => 'AB', // Less than 3 chars
            'harga' => '',  // Missing
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors(['nama', 'harga']);
    }

    /** @test */
    public function test_kategori_permainan_edit_form_displays_existing_data()
    {
        $this->actingAs($this->admin);

        $response = $this->get(route('kategori-permainan.edit', $this->playstation->id));

        $response->assertStatus(200);
        $response->assertViewIs('kategori-permainan.edit');
        $response->assertSee('Edit Kategori Permainan');
        $response->assertSee('Nama Permainan');
        $response->assertSee('Harga Dasar');
        $response->assertSee('PS5 Ultimate');
        $response->assertSee('15000');
    }

    /** @test */
    public function test_kategori_permainan_update_updates_record_and_redirects_with_notification()
    {
        $this->actingAs($this->admin);

        $response = $this->put(route('kategori-permainan.update', $this->playstation->id), [
            'nama' => 'PS5 Pro Enhanced',
            'harga' => 20000,
        ]);

        $response->assertStatus(302);
        $response->assertRedirect(route('kategori-permainan.index'));
        $response->assertSessionHas('success', 'Data kategori permainan berhasil diupdate.');

        $this->assertDatabaseHas('playstations', [
            'id' => $this->playstation->id,
            'nama' => 'PS5 Pro Enhanced',
            'harga' => 20000,
        ]);
    }

    /** @test */
    public function test_kategori_permainan_destroy_deletes_record_and_redirects_with_notification()
    {
        $this->actingAs($this->admin);

        $itemToDelete = Playstation::create([
            'nama' => 'PS2 Retro Test',
            'harga' => 5000,
        ]);

        $response = $this->delete(route('kategori-permainan.destroy', $itemToDelete->id));

        $response->assertStatus(302);
        $response->assertRedirect(route('kategori-permainan.index'));
        $response->assertSessionHas('success', 'Data kategori permainan berhasil dihapus.');

        $this->assertDatabaseMissing('playstations', [
            'id' => $itemToDelete->id,
        ]);
    }

    /** @test */
    public function test_station_playstation_and_billiard_remain_correctly_linked_without_regression()
    {
        $this->assertEquals($this->playstation->id, $this->psDevice->fresh()->playstation_id);
        $this->assertEquals('PS5 Ultimate', $this->psDevice->fresh()->playstation->nama);
        $this->assertEquals(15000, $this->psDevice->fresh()->playstation->harga);

        $this->assertEquals($this->billiard->id, $this->billiardDevice->fresh()->playstation_id);
        $this->assertEquals('Meja Biliar 9ft', $this->billiardDevice->fresh()->playstation->nama);
        $this->assertEquals(35000, $this->billiardDevice->fresh()->playstation->harga);
    }
}
