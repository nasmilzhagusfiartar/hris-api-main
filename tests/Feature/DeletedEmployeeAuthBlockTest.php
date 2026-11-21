<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureUserIsActive;
use App\Models\EmployeeProfile;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DeletedEmployeeAuthBlockTest extends TestCase
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

    private function createUserWithProfile(string $role, string $email): array
    {
        $user = User::factory()->create([
            'email' => $email,
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
        ]);
        $user->syncRoles([$role]);

        $profile = $user->employeeProfile()->create([
            'code' => 'EMP'.rand(100000, 999999),
            'identity_number' => '3171'.rand(100000000000, 999999999999),
            'phone' => '0812'.rand(10000000, 99999999),
            'date_of_birth' => '1995-01-01',
            'gender' => 'male',
            'place_of_birth' => 'Jakarta',
            'address' => 'Jakarta',
            'city' => 'Jakarta',
            'postal_code' => '10110',
        ]);

        return [$user, $profile];
    }

    public function test_deleted_employee_cannot_login_old_tokens_are_revoked_and_restore_reactivates(): void
    {
        $hr = User::factory()->create(['email' => 'hr-deact@example.com', 'is_active' => true]);
        $hr->syncRoles(['hr']);

        [$empUser, $empProfile] = $this->createUserWithProfile('employee', 'deleted-emp@example.com');

        // 1. Employee logs in before deletion and obtains a valid Sanctum token
        $loginBefore = $this->postJson('/api/v1/login', [
            'email' => 'deleted-emp@example.com',
            'password' => 'Secret123!',
        ])->assertStatus(200);
        $oldToken = $loginBefore->json('data.token');
        $this->assertNotEmpty($oldToken);

        // Verify old token works before deletion
        $this->withHeader('Authorization', 'Bearer '.$oldToken)
            ->getJson('/api/v1/me')
            ->assertStatus(200);

        // 2. HR deletes the employee
        Sanctum::actingAs($hr);
        $this->deleteJson("/api/v1/employees/{$empProfile->id}")
            ->assertStatus(200);

        // User is deactivated and all Sanctum tokens are revoked
        $empUser->refresh();
        $this->assertFalse((bool) $empUser->is_active);
        $this->assertSame(0, $empUser->tokens()->count());

        // 3. Old token is rejected
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$oldToken)
            ->getJson('/api/v1/me')
            ->assertStatus(401);

        // 4. Even if a token existed on a deactivated user, active.user middleware blocks it (403)
        $forcedToken = $empUser->createToken('forced')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$forcedToken)
            ->getJson('/api/v1/me')
            ->assertStatus(403)
            ->assertJsonPath('message', EnsureUserIsActive::DEACTIVATED_MESSAGE);

        // 5. Fresh login attempt is rejected with 403 + exact message
        $this->postJson('/api/v1/login', [
            'email' => 'deleted-emp@example.com',
            'password' => 'Secret123!',
        ])->assertStatus(403)
            ->assertJsonPath('message', EnsureUserIsActive::DEACTIVATED_MESSAGE);

        // 6. Audit log recorded account_deactivated
        if (Schema::hasTable('telescope_entries')) {
            $deactivatedLog = DB::table('telescope_entries')
                ->where('content', 'like', '%account_deactivated%')
                ->where('content', 'like', '%deleted-emp@example.com%')
                ->exists();
            $this->assertTrue($deactivatedLog);
        }

        // 7. Restore employee -> user is reactivated & can log in again
        EmployeeProfile::withTrashed()->findOrFail($empProfile->id)->restore();
        $empUser->refresh();
        $this->assertTrue((bool) $empUser->is_active);

        $this->postJson('/api/v1/login', [
            'email' => 'deleted-emp@example.com',
            'password' => 'Secret123!',
        ])->assertStatus(200);

        if (Schema::hasTable('telescope_entries')) {
            $reactivatedLog = DB::table('telescope_entries')
                ->where('content', 'like', '%account_reactivated%')
                ->where('content', 'like', '%deleted-emp@example.com%')
                ->exists();
            $this->assertTrue($reactivatedLog);
        }
    }

    public function test_super_admin_cannot_be_deactivated_via_employee_deletion(): void
    {
        $hr = User::factory()->create(['email' => 'hr-sa-check@example.com', 'is_active' => true]);
        $hr->syncRoles(['hr']);

        [$saUser, $saProfile] = $this->createUserWithProfile('super-admin', 'sa-protected@example.com');

        Sanctum::actingAs($hr);
        $this->deleteJson("/api/v1/employees/{$saProfile->id}")
            ->assertStatus(403);

        $saUser->refresh();
        $this->assertTrue((bool) $saUser->is_active);
        $this->assertNull($saProfile->fresh()->deleted_at);
    }
}
