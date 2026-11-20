<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class MfaAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
        ]);

        config([
            'mfa.enabled' => true,
            'mfa.enforce_roles' => [],
            'mfa.issuer' => 'HIP HRIS',
            'mfa.window' => 1,
        ]);
    }

    private function createUserWithRole(string $role, string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'password' => Hash::make('password123'),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function enrollMfaForUser(User $user): array
    {
        $google2fa = new Google2FA();
        Sanctum::actingAs($user->fresh());

        $setupRes = $this->postJson('/api/v1/mfa/setup')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['secret', 'qr_code_svg', 'otpauth_url'],
            ]);

        $secret = $setupRes->json('data.secret');
        $svg = $setupRes->json('data.qr_code_svg');
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringNotContainsString('chart.googleapis.com', $svg);
        $this->assertStringNotContainsString('qrserver.com', $svg);

        $validCode = $google2fa->getCurrentOtp($secret);

        Sanctum::actingAs($user->fresh());
        $enableRes = $this->postJson('/api/v1/mfa/enable', ['code' => $validCode])
            ->assertOk()
            ->assertJsonPath('data.mfa_enabled', true);

        $recoveryCodes = $enableRes->json('data.recovery_codes');
        $this->assertCount(8, $recoveryCodes);

        return [
            'secret' => $secret,
            'recovery_codes' => $recoveryCodes,
        ];
    }

    public function test_login_without_mfa_issues_token_and_hides_mfa_secrets(): void
    {
        $user = $this->createUserWithRole('employee', 'emp@example.com');

        $response = $this->postJson('/api/v1/login', [
            'email' => 'emp@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.email', 'emp@example.com')
            ->assertJsonPath('data.mfa_enabled', false)
            ->assertJsonMissingPath('data.mfa_secret')
            ->assertJsonMissingPath('data.mfa_recovery_codes')
            ->assertJsonMissingPath('data.mfa_enabled_at');

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertArrayNotHasKey('mfa_secret', $user->fresh()->toArray());
        $this->assertArrayNotHasKey('mfa_recovery_codes', $user->fresh()->toArray());
    }

    public function test_login_with_mfa_valid_and_invalid_totp_code(): void
    {
        $user = $this->createUserWithRole('employee', 'mfa-user@example.com');
        $enrolled = $this->enrollMfaForUser($user);
        $google2fa = new Google2FA();

        // Step 1: Login should require MFA and NOT issue access token
        $loginRes = $this->postJson('/api/v1/login', [
            'email' => 'mfa-user@example.com',
            'password' => 'password123',
        ]);

        $loginRes->assertOk()
            ->assertJsonPath('data.mfa_required', true)
            ->assertJsonMissingPath('data.token');

        $mfaToken = $loginRes->json('data.mfa_token');
        $this->assertNotEmpty($mfaToken);

        // Wrong code -> 422 with remaining_attempts = 4
        $this->postJson('/api/v1/login/mfa-verify', [
            'mfa_token' => $mfaToken,
            'code' => '000000',
        ])->assertStatus(422)
            ->assertJsonPath('data.remaining_attempts', 4);

        // Valid code -> 200 with Sanctum token
        $validCode = $google2fa->getCurrentOtp($enrolled['secret']);
        $verifyRes = $this->postJson('/api/v1/login/mfa-verify', [
            'mfa_token' => $mfaToken,
            'code' => $validCode,
        ]);

        $verifyRes->assertOk()
            ->assertJsonPath('data.mfa_enabled', true);
        $this->assertNotEmpty($verifyRes->json('data.token'));

        // Reusing single-use mfa_token must fail with 401
        $this->postJson('/api/v1/login/mfa-verify', [
            'mfa_token' => $mfaToken,
            'code' => $validCode,
        ])->assertStatus(401);
    }

    public function test_login_with_recovery_code_is_single_use(): void
    {
        $user = $this->createUserWithRole('employee', 'recovery@example.com');
        $enrolled = $this->enrollMfaForUser($user);
        $firstRecoveryCode = $enrolled['recovery_codes'][0];

        $loginRes = $this->postJson('/api/v1/login', [
            'email' => 'recovery@example.com',
            'password' => 'password123',
        ])->assertOk();
        $mfaToken = $loginRes->json('data.mfa_token');

        // First use succeeds
        $this->postJson('/api/v1/login/mfa-verify', [
            'mfa_token' => $mfaToken,
            'recovery_code' => $firstRecoveryCode,
        ])->assertOk();

        // Remaining recovery codes should be 7
        $this->assertCount(7, $user->fresh()->mfa_recovery_codes);

        // Second login + reusing same recovery code must fail
        $loginRes2 = $this->postJson('/api/v1/login', [
            'email' => 'recovery@example.com',
            'password' => 'password123',
        ])->assertOk();
        $mfaToken2 = $loginRes2->json('data.mfa_token');

        $this->postJson('/api/v1/login/mfa-verify', [
            'mfa_token' => $mfaToken2,
            'recovery_code' => $firstRecoveryCode,
        ])->assertStatus(422);
    }

    public function test_lockout_after_five_failed_mfa_attempts(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $user = $this->createUserWithRole('employee', 'lockout@example.com');
        $this->enrollMfaForUser($user);

        $loginRes = $this->postJson('/api/v1/login', [
            'email' => 'lockout@example.com',
            'password' => 'password123',
        ])->assertOk();
        $mfaToken = $loginRes->json('data.mfa_token');

        for ($i = 1; $i <= 4; $i++) {
            $this->postJson('/api/v1/login/mfa-verify', [
                'mfa_token' => $mfaToken,
                'code' => '999999',
            ])->assertStatus(422)
                ->assertJsonPath('data.remaining_attempts', 5 - $i);
        }

        // 5th failure locks account (423) and invalidates token
        $this->postJson('/api/v1/login/mfa-verify', [
            'mfa_token' => $mfaToken,
            'code' => '999999',
        ])->assertStatus(423)
            ->assertJsonPath('data.locked', true)
            ->assertJsonPath('data.lockout_minutes', 15);

        // Subsequent login while locked returns 423
        $this->postJson('/api/v1/login', [
            'email' => 'lockout@example.com',
            'password' => 'password123',
        ])->assertStatus(423)
            ->assertJsonPath('data.locked', true);
    }

    public function test_enforced_role_requires_mfa_setup_and_cannot_disable_mfa(): void
    {
        config(['mfa.enforce_roles' => ['admin', 'security']]);

        $admin = $this->createUserWithRole('admin', 'enforced-admin@example.com');

        // Login succeeds but returns mfa_setup_required: true
        $loginRes = $this->postJson('/api/v1/login', [
            'email' => 'enforced-admin@example.com',
            'password' => 'password123',
        ])->assertOk()
            ->assertJsonPath('data.mfa_setup_required', true);

        $token = $loginRes->json('data.token');

        // /me and /mfa/status are allowed
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
        $this->withToken($token)->getJson('/api/v1/mfa/status')
            ->assertOk()
            ->assertJsonPath('data.mfa_required_by_role', true);

        // Other protected endpoints return 403 until MFA is enabled
        $this->withToken($token)->getJson('/api/v1/settings')
            ->assertStatus(403)
            ->assertJsonPath('data.mfa_setup_required', true);

        // Enroll MFA
        $enrolled = $this->enrollMfaForUser($admin);
        $google2fa = new Google2FA();

        // Now protected endpoints succeed
        $this->actingAs($admin->fresh(), 'sanctum')
            ->getJson('/api/v1/settings')
            ->assertOk();

        // Attempting to disable MFA for enforced role is rejected with 403
        $this->actingAs($admin->fresh(), 'sanctum')
            ->postJson('/api/v1/mfa/disable', [
                'password' => 'password123',
                'code' => $google2fa->getCurrentOtp($enrolled['secret']),
            ])->assertStatus(403);
    }

    public function test_mfa_enabled_false_bypasses_all_mfa_logic(): void
    {
        $user = $this->createUserWithRole('admin', 'bypass@example.com');
        $this->enrollMfaForUser($user);

        config([
            'mfa.enabled' => false,
            'mfa.enforce_roles' => ['admin'],
        ]);

        // Login bypasses MFA challenge and immediately returns token
        $loginRes = $this->postJson('/api/v1/login', [
            'email' => 'bypass@example.com',
            'password' => 'password123',
        ])->assertOk()
            ->assertJsonMissingPath('data.mfa_required');

        $this->assertNotEmpty($loginRes->json('data.token'));
    }

    public function test_admin_can_reset_user_mfa_and_artisan_command_works(): void
    {
        $admin = $this->createUserWithRole('admin', 'superadmin@example.com');
        $employee = $this->createUserWithRole('employee', 'target@example.com');
        $hr = $this->createUserWithRole('hr', 'hr@example.com');

        $this->enrollMfaForUser($employee);
        $this->assertTrue($employee->fresh()->hasMfaEnabled());

        // Non-admin cannot reset MFA -> 403
        Sanctum::actingAs($hr);
        $this->postJson("/api/v1/users/{$employee->id}/mfa/reset")
            ->assertStatus(403);

        // Admin resets target user MFA -> 200
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/users/{$employee->id}/mfa/reset")
            ->assertOk()
            ->assertJsonPath('data.mfa_enabled', false);

        $this->assertFalse($employee->fresh()->hasMfaEnabled());

        // Re-enroll and test emergency artisan command
        $this->enrollMfaForUser($employee);
        $this->assertTrue($employee->fresh()->hasMfaEnabled());

        $this->artisan('mfa:reset', ['email' => 'target@example.com'])
            ->assertExitCode(0);

        $this->assertFalse($employee->fresh()->hasMfaEnabled());
    }
}
