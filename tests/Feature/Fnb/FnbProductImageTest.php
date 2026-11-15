<?php

namespace Tests\Feature\Fnb;

use App\Models\Fnb;
use App\Models\PriceGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FnbProductImageTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected PriceGroup $priceGroup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'name' => 'Admin FnB Test',
            'role' => 'admin',
            'email' => 'admin-fnb@test.local',
        ]);

        $this->priceGroup = PriceGroup::create([
            'nama' => 'Snack & Minuman',
            'harga' => 10000,
        ]);
    }

    /** @test */
    public function admin_can_upload_fnb_with_valid_image_jpg_png()
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('snack.jpg', 400, 400)->size(1500);

        $response = $this->actingAs($this->admin)->post('/fnb', [
            'nama' => 'Chiki Balls',
            'price_group_id' => $this->priceGroup->id,
            'stok_type' => 'limit',
            'stok' => 50,
            'deskripsi' => 'Snack renyah gurih',
            'image' => $file,
        ]);

        $response->assertRedirect('/fnb');

        $fnb = Fnb::where('nama', 'Chiki Balls')->first();
        $this->assertNotNull($fnb);
        $this->assertNotNull($fnb->image);
        Storage::disk('public')->assertExists($fnb->image);
    }

    /** @test */
    public function fnb_upload_rejects_invalid_mime_and_oversized_file()
    {
        Storage::fake('public');

        // Test invalid mime type (pdf)
        $invalidFile = UploadedFile::fake()->create('document.pdf', 500, 'application/pdf');
        $response = $this->actingAs($this->admin)->post('/fnb', [
            'nama' => 'Invalid File Fnb',
            'price_group_id' => $this->priceGroup->id,
            'stok_type' => 'unlimit',
            'image' => $invalidFile,
        ]);
        $response->assertSessionHasErrors(['image']);

        // Test oversized file (> 2048 KB)
        $oversizedFile = UploadedFile::fake()->image('large.jpg')->size(2500);
        $response2 = $this->actingAs($this->admin)->post('/fnb', [
            'nama' => 'Large Image Fnb',
            'price_group_id' => $this->priceGroup->id,
            'stok_type' => 'unlimit',
            'image' => $oversizedFile,
        ]);
        $response2->assertSessionHasErrors(['image']);
    }

    /** @test */
    public function fnb_update_replaces_old_image_and_deletes_previous_file_from_storage()
    {
        Storage::fake('public');

        $initialFile = UploadedFile::fake()->image('initial.jpg', 200, 200);
        $path = $initialFile->store('fnb', 'public');

        $fnb = Fnb::create([
            'nama' => 'Teh Botol',
            'price_group_id' => $this->priceGroup->id,
            'harga_jual' => $this->priceGroup->harga,
            'stok' => 20,
            'image' => $path,
        ]);

        Storage::disk('public')->assertExists($path);

        $newFile = UploadedFile::fake()->image('updated.png', 300, 300);

        $response = $this->actingAs($this->admin)->put("/fnb/{$fnb->id}", [
            'nama' => 'Teh Botol Sosro',
            'price_group_id' => $this->priceGroup->id,
            'stok_type' => 'limit',
            'stok' => 25,
            'image' => $newFile,
        ]);

        $response->assertRedirect('/fnb');

        $fnb->refresh();
        Storage::disk('public')->assertMissing($path);
        Storage::disk('public')->assertExists($fnb->image);
        $this->assertNotEquals($path, $fnb->image);
    }

    /** @test */
    public function fnb_destroy_removes_image_from_storage()
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('todelete.jpg');
        $path = $file->store('fnb', 'public');

        $fnb = Fnb::create([
            'nama' => 'Pop Mie',
            'price_group_id' => $this->priceGroup->id,
            'harga_jual' => $this->priceGroup->harga,
            'stok' => 10,
            'image' => $path,
        ]);

        Storage::disk('public')->assertExists($path);

        $response = $this->actingAs($this->admin)->delete("/fnb/{$fnb->id}");
        $response->assertRedirect('/fnb');

        $this->assertDatabaseMissing('fnbs', ['id' => $fnb->id]);
        Storage::disk('public')->assertMissing($path);
    }

    /** @test */
    public function fnb_image_url_accessor_returns_placeholder_when_image_is_null_or_missing()
    {
        // Case 1: image is null
        $fnbNull = new Fnb(['nama' => 'No Image Product', 'image' => null]);
        $this->assertEquals(asset('img/fnb-placeholder.svg'), $fnbNull->image_url);

        // Case 2: image is non-null but missing in public storage
        Storage::fake('public');
        $fnbMissing = new Fnb(['nama' => 'Missing File Product', 'image' => 'fnb/missing.jpg']);
        $this->assertEquals(asset('img/fnb-placeholder.svg'), $fnbMissing->image_url);

        // Case 3: image exists in public storage
        Storage::disk('public')->put('fnb/exists.jpg', 'content');
        $fnbExists = new Fnb(['nama' => 'Existing File Product', 'image' => 'fnb/exists.jpg']);
        $this->assertEquals(asset('storage/fnb/exists.jpg'), $fnbExists->image_url);
    }

    /** @test */
    public function fnb_index_renders_product_image_thumbnails()
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('item.png', 100, 100);
        $path = $file->store('fnb', 'public');

        $fnb = Fnb::create([
            'nama' => 'Sosis Bakar',
            'price_group_id' => $this->priceGroup->id,
            'harga_jual' => $this->priceGroup->harga,
            'stok' => 15,
            'image' => $path,
        ]);

        $response = $this->actingAs($this->admin)->get('/fnb');
        $response->assertStatus(200);
        $response->assertSee('Gambar');
        $response->assertSee($fnb->image_url, false);
    }
}
