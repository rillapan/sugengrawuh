<?php

namespace Tests\Unit\Deployment;

use App\Console\Commands\BillingDaemonCommand;
use App\Services\Hardware\Drivers\SamsungTvDriver;
use App\Services\Hardware\Transports\FakeSamsungWebSocketTransport;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    /** @test */
    public function blade_views_and_css_contain_no_external_font_or_cdn_dependencies()
    {
        $this->assertFileExists(public_path('css/offline-fonts.css'));

        $appCss = file_get_contents(public_path('css/app.css'));
        $this->assertStringNotContainsString('fonts.googleapis.com', $appCss);
        $this->assertStringContainsString('offline-fonts.css', $appCss);

        $viewsDir = resource_path('views');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewsDir));

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $content = file_get_contents($file->getPathname());
                $relativePath = str_replace(base_path() . '/', '', $file->getPathname());

                $this->assertStringNotContainsString(
                    'fonts.googleapis.com',
                    $content,
                    "External Google Fonts link found in {$relativePath}"
                );
                $this->assertStringNotContainsString(
                    'fonts.gstatic.com',
                    $content,
                    "External gstatic link found in {$relativePath}"
                );
                $this->assertStringNotContainsString(
                    'cdnjs.cloudflare.com',
                    $content,
                    "External cdnjs link found in {$relativePath}"
                );
            }
        }
    }

    /** @test */
    public function samsung_tv_driver_wake_on_lan_handles_default_and_wol_helper_config()
    {
        $driver = new SamsungTvDriver(new FakeSamsungWebSocketTransport());

        // Invalid MAC returns false
        $this->assertFalse($driver->sendWakeOnLan('invalid-mac'));

        // Default config (wol_helper.enabled = false)
        Config::set('hardware.wol_helper.enabled', false);
        $this->assertTrue($driver->sendWakeOnLan('AA:BB:CC:DD:EE:FF', '192.168.1.50'));

        // With wol_helper enabled (pointed to non-listening local port with fast timeout so it degrades gracefully without failing UDP)
        Config::set('hardware.wol_helper.enabled', true);
        Config::set('hardware.wol_helper.url', 'http://127.0.0.1:19999/wol');
        Config::set('hardware.wol_helper.token', 'test-secret-token');
        Config::set('hardware.wol_helper.timeout_ms', 200);

        $this->assertTrue($driver->sendWakeOnLan('AA:BB:CC:DD:EE:FF', '192.168.1.50'));
    }

    /** @test */
    public function billing_daemon_heartbeat_constants_and_scheduler_skip_anti_duplication_work_properly()
    {
        $this->assertSame('billing:daemon:heartbeat', BillingDaemonCommand::HEARTBEAT_CACHE_KEY);
        $this->assertSame(15, BillingDaemonCommand::HEARTBEAT_TTL_SECONDS);

        $schedule = app(Schedule::class);
        $events = collect($schedule->events());

        $workerCommands = [
            'billing:worker --once',
            'billing:tv-alert-worker --once',
            'billing:automation-worker --once',
        ];

        // 1. When daemon heartbeat is NOT in cache, fallback workers should pass filters
        Cache::forget(BillingDaemonCommand::HEARTBEAT_CACHE_KEY);

        foreach ($workerCommands as $cmd) {
            $event = $events->first(fn ($e) => str_contains($e->command ?? '', $cmd));
            $this->assertNotNull($event, "Scheduled command {$cmd} should be registered");
            $this->assertTrue($event->filtersPass($this->app), "Scheduled command {$cmd} should run when daemon heartbeat is absent");
        }

        // 2. When daemon heartbeat IS present in cache, fallback every-minute workers must be skipped
        Cache::put(BillingDaemonCommand::HEARTBEAT_CACHE_KEY, [
            'worker_id' => 'test@localhost',
            'timestamp' => time(),
        ], 15);

        foreach ($workerCommands as $cmd) {
            $event = $events->first(fn ($e) => str_contains($e->command ?? '', $cmd));
            $this->assertFalse($event->filtersPass($this->app), "Scheduled command {$cmd} must be skipped when daemon heartbeat is active");
        }

        // Clean up cache
        Cache::forget(BillingDaemonCommand::HEARTBEAT_CACHE_KEY);
    }
}

