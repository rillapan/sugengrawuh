<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Http\Middleware\CheckRole;
use App\Http\Controllers\AppSettingsController;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RbacAndSecurityTest extends TestCase
{
    /**
     * Test that active database connection in test environment is strictly isolated to kasir_ps_test.
     *
     * @return void
     */
    public function test_database_connection_is_isolated_to_test_database()
    {
        $resolvedDb = config('database.connections.mysql.database');
        $this->assertEquals('kasir_ps_test', $resolvedDb);

        $actualPdoDb = \Illuminate\Support\Facades\DB::connection()->getPdo()->query('SELECT DATABASE()')->fetchColumn();
        $this->assertEquals('kasir_ps_test', $actualPdoDb);
        $this->assertNotEquals('billing-sugeng-rawuh', $actualPdoDb);
    }

    /**
     * Test User model role helper methods with lowercase, uppercase, and mixed-case roles.
     *
     * @return void
     */
    public function test_user_role_helper_methods_are_case_insensitive()
    {
        $owner = new User(['role' => 'OWNER']);
        $admin = new User(['role' => 'Admin']);
        $kasir = new User(['role' => 'kasir']);

        $this->assertTrue($owner->isOwner());
        $this->assertFalse($owner->isAdmin());
        $this->assertFalse($owner->isKasir());

        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($admin->isOwner());
        $this->assertFalse($admin->isKasir());

        $this->assertTrue($kasir->isKasir());
        $this->assertFalse($kasir->isAdmin());
        $this->assertFalse($kasir->isOwner());

        $this->assertTrue($owner->hasRole(['admin', 'owner']));
        $this->assertFalse($owner->hasRole(['admin', 'kasir']));
    }

    /**
     * Test CheckRole middleware allows authorized roles and aborts 403 on unauthorized roles.
     *
     * @return void
     */
    public function test_check_role_middleware_enforcement()
    {
        $middleware = new CheckRole();
        $request = Request::create('/test-endpoint', 'GET');

        // Test Owner accessing allowed route
        $owner = new User(['role' => 'owner']);
        $this->actingAs($owner);

        $allowedResponse = $middleware->handle($request, function ($req) {
            return response('ALLOWED');
        }, 'admin', 'kasir', 'owner');
        $this->assertEquals('ALLOWED', $allowedResponse->getContent());

        // Test Owner accessing mutation route (admin,kasir only) -> must throw 403
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Unauthorized action.');
        $middleware->handle($request, function ($req) {
            return response('MUTATION_EXECUTED');
        }, 'admin', 'kasir');
    }

    /**
     * Test CheckRole middleware always allows Admin.
     *
     * @return void
     */
    public function test_check_role_middleware_allows_admin()
    {
        $middleware = new CheckRole();
        $request = Request::create('/test-admin', 'GET');

        $admin = new User(['role' => 'admin']);
        $this->actingAs($admin);

        $response = $middleware->handle($request, function ($req) {
            return response('ADMIN_PASSED');
        }, 'kasir');

        $this->assertEquals('ADMIN_PASSED', $response->getContent());
    }

    /**
     * Test AppSettingsController::resetTransactions is neutralized and returns redirect with error.
     *
     * @return void
     */
    public function test_reset_transactions_endpoint_is_neutralized()
    {
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $admin = new User(['id' => 1, 'name' => 'Admin', 'role' => 'admin']);
        $this->actingAs($admin);

        $response = $this->post('/settings/reset-transactions');

        $response->assertRedirect(route('settings.index'));
        $response->assertSessionHas('gagal', 'Operasi reset data transaksi telah dinonaktifkan demi integritas data dan keamanan operasional.');
    }

    /**
     * Test Owner is blocked with 403 from mutation endpoints (e.g. transaction create, store).
     *
     * @return void
     */
    public function test_owner_is_forbidden_from_transaction_mutation()
    {
        $owner = new User(['id' => 2, 'name' => 'Owner', 'role' => 'owner']);
        $this->actingAs($owner);

        $response = $this->get('/transaction/create');
        $response->assertStatus(403);
    }

    /**
     * Test Kasir is blocked with 403 from admin-only endpoints (e.g. app settings, transaction delete).
     *
     * @return void
     */
    public function test_kasir_is_forbidden_from_admin_endpoints()
    {
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $kasir = new User(['id' => 3, 'name' => 'Kasir', 'role' => 'kasir']);
        $this->actingAs($kasir);

        // App settings is admin only
        $responseSettings = $this->get('/settings');
        $responseSettings->assertStatus(403);

        // Transaction deletion is admin only
        $responseDelete = $this->delete('/transaction/1');
        $responseDelete->assertStatus(403);
    }
}
