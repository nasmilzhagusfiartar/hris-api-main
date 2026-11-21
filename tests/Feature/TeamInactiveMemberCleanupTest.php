<?php

namespace Tests\Feature;

use App\Models\EmployeeProfile;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TeamInactiveMemberCleanupTest extends TestCase
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

    private function createEmployee(string $email): array
    {
        $user = User::factory()->create([
            'email' => $email,
            'is_active' => true,
        ]);
        $user->syncRoles(['employee']);

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

        $profile->jobInformation()->create([
            'job_title' => 'Sales Staff',
            'department' => 'sales',
            'years_experience' => 2,
            'status' => 'active',
            'employment_type' => 'full_time',
            'work_location' => 'store',
            'start_date' => '2025-01-01',
            'monthly_salary' => 5000000,
            'skill_level' => 'intermediate',
        ]);

        return [$user, $profile];
    }

    public function test_deleted_and_resigned_employees_are_removed_from_teams_lead_is_unassigned_and_restore_does_not_rejoin(): void
    {
        $hr = User::factory()->create(['email' => 'hr-team-clean@example.com', 'is_active' => true]);
        $hr->syncRoles(['hr']);

        [$leadUser, $leadProfile] = $this->createEmployee('lead-emp@example.com');
        [$delUser, $delProfile] = $this->createEmployee('del-emp@example.com');
        [$resignUser, $resignProfile] = $this->createEmployee('resign-team-emp@example.com');
        [$stayUser, $stayProfile] = $this->createEmployee('stay-emp@example.com');

        $team = Team::create([
            'name' => 'Retail Team A',
            'expected_size' => 10,
            'description' => 'Retail operations',
            'icon' => 'team-icons/default.png',
            'department' => 'sales',
            'status' => 'active',
            'team_lead_id' => $leadUser->id,
            'responsibilities' => ['Sell toys', 'Stock check', 'Store care'],
        ]);

        foreach ([$leadProfile, $delProfile, $resignProfile, $stayProfile] as $ep) {
            TeamMember::create([
                'team_id' => $team->id,
                'employee_id' => $ep->id,
                'joined_at' => now(),
                'left_at' => null,
            ]);
        }

        Sanctum::actingAs($hr);

        // Initially 4 active members
        $this->getJson("/api/v1/teams/{$team->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.members_count', 4);

        // 1. Delete regular employee -> removed from team (left_at set) & members_count becomes 3
        $this->deleteJson("/api/v1/employees/{$delProfile->id}")->assertStatus(200);
        $this->assertNotNull(
            TeamMember::where('team_id', $team->id)->where('employee_id', $delProfile->id)->value('left_at')
        );
        $this->getJson("/api/v1/teams/{$team->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.members_count', 3);

        // 2. Resign employee -> removed from team & members_count becomes 2
        $this->putJson("/api/v1/employees/{$resignProfile->id}", [
            'status' => 'resigned',
            'end_date' => now()->toDateString(),
        ])->assertStatus(200);

        $this->getJson("/api/v1/teams/{$team->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.members_count', 2);

        // 3. Delete team lead -> team_lead_id becomes null, removed from team, members_count becomes 1
        $this->deleteJson("/api/v1/employees/{$leadProfile->id}")->assertStatus(200);
        $team->refresh();
        $this->assertNull($team->team_lead_id);

        $detailRes = $this->getJson("/api/v1/teams/{$team->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.members_count', 1);
        $this->assertCount(1, $detailRes->json('data.members'));
        $this->assertSame($stayProfile->id, $detailRes->json('data.members.0.employee.id'));

        // Audit log recorded team_member_removed & team_lead_unassigned
        if (Schema::hasTable('telescope_entries')) {
            $this->assertTrue(
                DB::table('telescope_entries')->where('content', 'like', '%team_member_removed%')->exists()
            );
            $this->assertTrue(
                DB::table('telescope_entries')->where('content', 'like', '%team_lead_unassigned%')->exists()
            );
        }

        // 4. Restore deleted employee -> MUST NOT automatically rejoin team!
        EmployeeProfile::withTrashed()->findOrFail($delProfile->id)->restore();
        $this->getJson("/api/v1/teams/{$team->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.members_count', 1);

        // 5. Team List members_count also reflects only active member (1)
        $listRes = $this->getJson('/api/v1/teams')->assertStatus(200);
        $teamRow = collect($listRes->json('data'))->firstWhere('id', $team->id);
        $this->assertSame(1, $teamRow['members_count']);
    }
}
