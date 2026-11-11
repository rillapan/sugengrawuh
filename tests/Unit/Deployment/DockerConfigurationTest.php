<?php

namespace Tests\Unit\Deployment;

use Tests\TestCase;

class DockerConfigurationTest extends TestCase
{
    /** @test */
    public function dockerignore_excludes_sensitive_env_and_local_artifacts_while_retaining_vendor()
    {
        $path = base_path('.dockerignore');
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $lines = array_map('trim', explode("\n", $content));

        $requiredExclusions = [
            '.env',
            '.env.operational.backup',
            '.env.production.original',
            '.env.testing',
            '.env.docker',
            '.git',
            '.superpowers',
            'node_modules',
            'storage/logs/*.log',
            'dist',
            'offline-bundle',
            'data',
            '*.sql',
            '*.sql.gz',
        ];

        foreach ($requiredExclusions as $item) {
            $this->assertContains($item, $lines, ".dockerignore must exclude {$item}");
        }

        $this->assertNotContains('vendor', $lines, '.dockerignore must keep vendor/ in build context for deterministic builds');
        $this->assertNotContains('vendor/', $lines, '.dockerignore must keep vendor/ in build context for deterministic builds');
    }

    /** @test */
    public function dockerfile_targets_linux_amd64_and_installs_required_extensions_without_baking_secrets()
    {
        $path = base_path('Dockerfile');
        $this->assertFileExists($path);
        $this->assertFileExists(base_path('docker/php/php-production.ini'));

        $dockerfile = file_get_contents($path);

        $this->assertStringContainsString('FROM --platform=linux/amd64 php:8.2-fpm-bookworm', $dockerfile);
        $this->assertStringContainsString('gosu', $dockerfile);
        $this->assertStringContainsString('pdo_mysql', $dockerfile);
        $this->assertStringContainsString('sockets', $dockerfile);
        $this->assertStringContainsString('gd', $dockerfile);
        $this->assertStringContainsString('intl', $dockerfile);
        $this->assertStringContainsString('zip', $dockerfile);
        $this->assertStringContainsString('bcmath', $dockerfile);
        $this->assertStringContainsString('pcntl', $dockerfile);
        $this->assertStringContainsString('opcache', $dockerfile);
        $this->assertStringContainsString('rm -f /var/www/html/.env', $dockerfile);
        $this->assertStringContainsString('/usr/src/storage-skel', $dockerfile);
        $this->assertStringContainsString('ENTRYPOINT ["/usr/local/bin/docker-entrypoint-sr.sh"]', $dockerfile);

        // Must never bake an APP_KEY=base64:... into Dockerfile
        $this->assertDoesNotMatchRegularExpression('/APP_KEY\s*=\s*base64:/i', $dockerfile);
    }

    /** @test */
    public function entrypoint_enforces_non_destructive_migrations_and_www_data_user_isolation()
    {
        $path = base_path('docker/entrypoint.sh');
        $this->assertFileExists($path);

        $entrypoint = file_get_contents($path);

        // Must never execute destructive database commands
        $this->assertStringNotContainsString('migrate:fresh', $entrypoint);
        $this->assertStringNotContainsString('migrate:reset', $entrypoint);
        $this->assertStringNotContainsString('migrate:refresh', $entrypoint);
        $this->assertStringNotContainsString('db:seed', $entrypoint);
        $this->assertStringNotContainsString('db:wipe', $entrypoint);

        // Must not call route:cache because routes/web.php and routes/api.php contain Closure routes
        $this->assertStringNotContainsString('route:cache', $entrypoint);

        // Must run safe incremental migrations and production-init as www-data with umask 0002
        $this->assertStringContainsString('umask 0002', $entrypoint);
        $this->assertStringContainsString('gosu www-data', $entrypoint);
        $this->assertStringContainsString('migrate --force', $entrypoint);
        $this->assertStringContainsString('app:production-init --reconcile', $entrypoint);
        $this->assertStringContainsString('billing:daemon', $entrypoint);
        $this->assertStringContainsString('schedule:run', $entrypoint);
    }

    /** @test */
    public function nginx_config_routes_to_app_fpm_and_blocks_sensitive_dotfiles()
    {
        $path = base_path('docker/nginx/default.conf');
        $this->assertFileExists($path);

        $nginx = file_get_contents($path);

        $this->assertStringContainsString('listen 80;', $nginx);
        $this->assertStringContainsString('root /var/www/html/public;', $nginx);
        $this->assertStringContainsString('fastcgi_pass app:9000;', $nginx);
        $this->assertStringContainsString('client_max_body_size 32M;', $nginx);
        $this->assertStringContainsString('X-Frame-Options "SAMEORIGIN"', $nginx);
        $this->assertStringContainsString('X-Content-Type-Options "nosniff"', $nginx);
        $this->assertStringContainsString('deny all;', $nginx);
    }

    /** @test */
    public function docker_compose_defines_all_five_services_on_linux_amd64_with_localhost_bound_mysql_and_port_8008()
    {
        $path = base_path('docker-compose.yml');
        $this->assertFileExists($path);

        $compose = file_get_contents($path);

        // All 5 required services and container names
        $this->assertStringContainsString('sugeng_rawuh_db', $compose);
        $this->assertStringContainsString('sugeng_rawuh_app', $compose);
        $this->assertStringContainsString('sugeng_rawuh_web', $compose);
        $this->assertStringContainsString('sugeng_rawuh_daemon', $compose);
        $this->assertStringContainsString('sugeng_rawuh_scheduler', $compose);

        // Target platform linux/amd64
        $this->assertGreaterThanOrEqual(5, substr_count($compose, 'platform: linux/amd64'));

        // MySQL strictly bound to 127.0.0.1 only
        $this->assertStringContainsString('127.0.0.1:${DB_LOCAL_PORT:-13306}:3306', $compose);
        $this->assertDoesNotMatchRegularExpression('/^\s*-\s*["\']?(?:0\.0\.0\.0:)?\d+:3306["\']?\s*$/m', $compose);

        // Web port 8008 default
        $this->assertStringContainsString('${APP_PORT:-8008}:80', $compose);

        // Host gateway for Windows WOL helper
        $this->assertStringContainsString('host.docker.internal:host-gateway', $compose);

        // Daemon heartbeat healthcheck and web /api/health healthcheck
        $this->assertStringContainsString('billing:daemon:heartbeat', $compose);
        $this->assertStringContainsString('http://127.0.0.1/api/health', $compose);

        // Named volumes
        $this->assertStringContainsString('sr_mysql_data:', $compose);
        $this->assertStringContainsString('sr_storage_data:', $compose);
        $this->assertStringContainsString('sr_public_assets:', $compose);
    }
}

