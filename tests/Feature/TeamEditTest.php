<?php

namespace Tests\Feature;

use App\Models\EmployeeProfile;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TeamEditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function createUserWithRole(string $role, string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'is_active' => true]);
        $user->syncRoles([$role]);

        return $user;
    }

    private function createEmployee(string $email): EmployeeProfile
    {
        $user = $this->createUserWithRole('employee', $email);

        return EmployeeProfile::create([
            'user_id' => $user->id,
            'code' => 'EMP202611'.rand(1000, 9999),
            'identity_number' => '3171'.rand(100000000000, 999999999999),
            'phone' => '0812'.rand(10000000, 99999999),
            'date_of_birth' => '1995-01-01',
            'gender' => 'male',
            'place_of_birth' => 'Jakarta',
            'address' => 'Jl. Test',
            'city' => 'Jakarta',
            'postal_code' => '10110',
        ]);
    }

    public function test_super_admin_admin_and_hr_can_edit_team_name_icon_status_lead_and_members(): void
    {
        Storage::fake('public');

        $team = Team::create([
            'name' => 'Initial Team',
            'department' => 'sales',
            'expected_size' => 10,
            'description' => 'Initial desc',
            'status' => 'active',
            'responsibilities' => ['Initial duty'],
        ]);

        $leadEmp = $this->createEmployee('lead1@example.com');
        $memberEmp = $this->createEmployee('member1@example.com');

        foreach (['super-admin', 'admin', 'hr'] as $idx => $role) {
            $actor = $this->createUserWithRole($role, "{$role}-edit-{$idx}@example.com");
            Sanctum::actingAs($actor);

            // Edit name, status, icon (upload), team lead, and empty responsibility slots via POST + _method=PUT
            $icon = UploadedFile::fake()->image("team-{$idx}.png", 100, 100);
            $res = $this->post("/api/v1/teams/{$team->id}", [
                '_method' => 'PUT',
                'name' => "Updated By {$role}",
                'department' => 'sales',
                'expected_size' => 12,
                'description' => "Desc by {$role}",
                'status' => 'forming',
                'team_lead_id' => $leadEmp->user_id,
                'icon' => $icon,
                'responsibilities' => ['Duty 1', '', ''],
            ], ['Accept' => 'application/json']);

            $res->assertStatus(200)
                ->assertJsonPath('data.name', "Updated By {$role}")
                ->assertJsonPath('data.status', 'forming')
                ->assertJsonPath('data.team_lead_id', $leadEmp->user_id);

            $this->assertNotNull($res->json('data.icon'));
        }

        // Test unassigning team lead and removing icon
        $hr = $this->createUserWithRole('hr', 'hr-unassign@example.com');
        Sanctum::actingAs($hr);

        $this->post("/api/v1/teams/{$team->id}", [
            '_method' => 'PUT',
            'name' => 'Team Without Lead',
            'status' => 'active',
            'team_lead_id' => '',
            'remove_icon' => '1',
        ], ['Accept' => 'application/json'])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Team Without Lead')
            ->assertJsonPath('data.team_lead_id', null)
            ->assertJsonPath('data.icon', null);

        // Test add & remove member
        $this->postJson("/api/v1/teams/{$team->id}/add-member", [
            'employee_id' => $memberEmp->id,
        ])->assertStatus(200);

        $this->postJson("/api/v1/teams/{$team->id}/remove-member", [
            'employee_id' => $memberEmp->id,
        ])->assertStatus(200);
    }
}
