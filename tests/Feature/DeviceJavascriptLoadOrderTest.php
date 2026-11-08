<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Playstation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceJavascriptLoadOrderTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Device $station;
    protected Playstation $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'name' => 'Admin JS Test',
            'role' => 'admin',
            'email' => 'admin_js@sugengrawuh.test',
        ]);

        $this->service = Playstation::create([
            'nama' => 'PS5 VIP JS',
            'harga' => 25000,
        ]);

        $this->station = Device::create([
            'nama' => 'Meja JS 01',
            'status' => 'Tersedia',
            'playstation_id' => $this->service->id,
            'session_generation' => 1,
        ]);
        $this->station->playstations()->attach($this->service->id);
    }

    /**
     * Test 01: Local jQuery asset exists on disk and is readable without internet.
     */
    public function test_jquery_asset_exists_locally(): void
    {
        $localJqueryPath = public_path('vendor/jquery/jquery.min.js');
        $this->assertFileExists($localJqueryPath);
        $this->assertGreaterThan(10000, filesize($localJqueryPath));

        $bootstrapBundlePath = public_path('vendor/bootstrap/js/bootstrap.bundle.min.js');
        $this->assertFileExists($bootstrapBundlePath);
    }

    /**
     * Test 02: In rendered /device HTML, jQuery is loaded BEFORE the page-level scripts.
     */
    public function test_jquery_is_loaded_before_device_page_scripts(): void
    {
        $response = $this->actingAs($this->admin)->get('/device');
        $response->assertStatus(200);

        $html = $response->getContent();

        // 1. Assert local asset references exist
        $this->assertStringContainsString('vendor/jquery/jquery.min.js', $html);
        $this->assertStringContainsString('vendor/bootstrap/js/bootstrap.bundle.min.js', $html);

        // 2. Assert exact load order: jQuery must precede $(document).ready and pollAutomationStatus
        $jqueryPos = strpos($html, 'vendor/jquery/jquery.min.js');
        $readyPos = strpos($html, '$(document).ready(function()');
        $pollPos = strpos($html, 'function pollAutomationStatus()');

        $this->assertNotFalse($jqueryPos, 'jQuery script tag must be present in HTML');
        $this->assertNotFalse($readyPos, '$(document).ready call must be present in HTML');
        $this->assertNotFalse($pollPos, 'pollAutomationStatus function must be present in HTML');

        $this->assertLessThan(
            $readyPos,
            $jqueryPos,
            'CRITICAL: jQuery must be loaded in DOM before $(document).ready is encountered to prevent "$ is not defined"'
        );

        $this->assertLessThan(
            $pollPos,
            $jqueryPos,
            'CRITICAL: jQuery must be loaded in DOM before page scripts are executed'
        );
    }

    /**
     * Test 03: Chart area demo does not log misleading error when myAreaChart is absent.
     */
    public function test_chart_area_demo_does_not_log_error_on_pages_without_canvas(): void
    {
        $chartDemoFile = public_path('js/demo/chart-area-demo.js');
        $this->assertFileExists($chartDemoFile);

        $contents = file_get_contents($chartDemoFile);
        $this->assertStringContainsString('if (!ctx)', $contents);
        $this->assertStringNotContainsString('Chart element with id "myAreaChart" not found', $contents);
    }

    /**
     * Test 04: Dashboard view still renders chart canvas for home analytics.
     */
    public function test_dashboard_still_renders_chart_elements(): void
    {
        $response = $this->actingAs($this->admin)->get('/home');
        $response->assertStatus(200);
        $response->assertSee('myAreaChart');
    }
}

