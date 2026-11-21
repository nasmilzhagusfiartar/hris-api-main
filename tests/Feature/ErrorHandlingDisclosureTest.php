<?php

namespace Tests\Feature;

use App\Helpers\ResponseHelper;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ErrorHandlingDisclosureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_server_error_hides_internal_details_when_app_debug_is_false(): void
    {
        config(['app.debug' => false]);

        Route::get('/api/v1/_test-boom', function () {
            throw new \RuntimeException('SQLSTATE[23000]: secret_table_name host=mysql_internal');
        });

        $res = $this->getJson('/api/v1/_test-boom')->assertStatus(500);
        $res->assertJson([
            'success' => false,
            'message' => ResponseHelper::GENERIC_SERVER_ERROR,
            'data' => null,
        ]);
        $this->assertStringNotContainsString('SQLSTATE', (string) $res->json('message'));
        $this->assertStringNotContainsString('secret_table_name', (string) $res->json('message'));
    }

    public function test_server_error_shows_details_when_app_debug_is_true(): void
    {
        config(['app.debug' => true]);

        Route::get('/api/v1/_test-boom-debug', function () {
            throw new \RuntimeException('Detailed debug error message');
        });

        $res = $this->getJson('/api/v1/_test-boom-debug')->assertStatus(500);
        $this->assertStringContainsString('Detailed debug error message', (string) $res->json('message'));
    }

    public function test_validation_not_found_and_forbidden_still_return_clear_messages_when_debug_is_false(): void
    {
        config(['app.debug' => false]);

        $hr = User::factory()->create(['email' => 'hr-err@example.com']);
        $hr->syncRoles(['hr']);
        $employee = User::factory()->create(['email' => 'emp-err@example.com']);
        $employee->syncRoles(['employee']);

        // 422 Validation
        Sanctum::actingAs($hr);
        $this->postJson('/api/v1/stores', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors']);

        // 404 Not Found
        $this->getJson('/api/v1/stores/999999')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Store Not Found');

        // 403 Forbidden
        Sanctum::actingAs($employee);
        $this->getJson('/api/v1/stores')
            ->assertStatus(403);
    }
}
