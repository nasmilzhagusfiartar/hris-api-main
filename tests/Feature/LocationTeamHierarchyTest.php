<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\EmployeeProfile;
use App\Models\JobInformation;
use App\Models\LeaveRequest;
use App\Models\Store;
use App\Models\Team;
use App\Models\TeamMember;
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

class LocationTeamHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->hr = User::factory()->create(['email' => 'hr-hierarchy@example.com', 'is_active' => true]);
        $this->hr->syncRoles(['hr']);
    }

    private function createEmployeeAtStore(string $email, Store $store, ?Team $team = null, string $role = 'employee'): EmployeeProfile
    {
        $user = User::factory()->create(['email' => $email, 'is_active' => true]);
        $user->syncRoles([$role]);

        $profile = EmployeeProfile::create([
            'user_id' => $user->id,
            'code' => 'EMP202611'.rand(1000, 9999),
            'identity_number' => '3171'.rand(100000000000, 999999999999),
            'phone' => '0812'.rand(10000000, 99999999),
            'date_of_birth' => '1994-05-05',
            'gender' => 'male',
            'place_of_birth' => 'Jakarta',
            'address' => 'Jl. Sudirman',
            'city' => 'Jakarta',
            'postal_code' => '10110',
        ]);

        JobInformation::create([
            'employee_id' => $profile->id,
            'job_title' => 'Staff',
            'store_id' => $store->id,
            'team_id' => $team?->id,
            'years_experience' => 2,
            'status' => 'active',
            'employment_type' => 'full_time',
            'work_location' => $store->type === Store::TYPE_HEAD_OFFICE ? 'office' : 'store',
            'start_date' => now()->subMonth()->toDateString(),
            'monthly_salary' => 5000000,
            'skill_level' => 'intermediate',
        ]);

        if ($team) {
            TeamMember::create([
                'team_id' => $team->id,
                'employee_id' => $profile->id,
                'joined_at' => now(),
            ]);
        }

        return $profile;
    }

    public function test_new_store_auto_creates_six_default_teams_and_head_office_rules_are_enforced(): void
    {
        Sanctum::actingAs($this->hr);

        // Creating a Store automatically creates the 6 exact default teams
        $storeRes = $this->postJson('/api/v1/stores', [
            'name' => 'Toys City Pondok Indah',
            'type' => 'store',
            'brand' => 'Toys City',
            'city' => 'Jakarta Selatan',
        ])->assertStatus(201);

        $storeId = $storeRes->json('data.id');
        $teamNames = collect($storeRes->json('data.teams'))->pluck('name')->sort()->values()->all();
        $expected = ['Cashier', 'E-Commerce', 'IT Support', 'Sales Counter', 'Store Management', 'Warehouse'];
        $this->assertSame($expected, $teamNames);

        // Only one active Head Office is allowed (migration already seeded HO-01)
        $this->postJson('/api/v1/stores', [
            'name' => 'Second Head Office',
            'type' => 'head_office',
            'brand' => 'PT Hero Intiputra',
            'city' => 'Bandung',
            'is_active' => true,
        ])->assertStatus(422)->assertJsonValidationErrors(['type']);

        // Team name must be unique per location, but same name allowed in a different location
        $this->postJson('/api/v1/teams', [
            'store_id' => $storeId,
            'name' => 'Cashier',
        ])->assertStatus(422)->assertJsonValidationErrors(['name']);

        $ho = Store::where('type', Store::TYPE_HEAD_OFFICE)->firstOrFail();
        $this->postJson('/api/v1/teams', [
            'store_id' => $ho->id,
            'name' => 'Cashier',
        ])->assertStatus(201)->assertJsonPath('data.store_id', $ho->id);
    }

    public function test_transferring_employee_to_team_in_another_location_ends_old_membership_and_updates_store(): void
    {
        Sanctum::actingAs($this->hr);

        $storeA = $this->postJson('/api/v1/stores', [
            'name' => 'Toys City Mall A',
            'brand' => 'Toys City',
            'city' => 'Jakarta',
        ])->assertStatus(201)->json('data');

        $storeB = $this->postJson('/api/v1/stores', [
            'name' => 'Toys City Mall B',
            'brand' => 'Toys City',
            'city' => 'Surabaya',
        ])->assertStatus(201)->json('data');

        $teamA = Team::where('store_id', $storeA['id'])->where('name', 'Cashier')->firstOrFail();
        $teamB = Team::where('store_id', $storeB['id'])->where('name', 'Warehouse')->firstOrFail();

        $emp = $this->createEmployeeAtStore('transfer@example.com', Store::find($storeA['id']), $teamA);

        // Transfer to Team B in Store B
        $this->postJson("/api/v1/teams/{$teamB->id}/add-member", [
            'employee_id' => $emp->id,
        ])->assertStatus(200);

        // Old membership at Store A is ended (left_at set)
        $oldMembership = TeamMember::where('team_id', $teamA->id)->where('employee_id', $emp->id)->first();
        $this->assertNotNull($oldMembership->left_at);

        // New membership at Store B is active and job_information.store_id updated
        $this->assertDatabaseHas('team_members', [
            'team_id' => $teamB->id,
            'employee_id' => $emp->id,
            'left_at' => null,
        ]);
        $this->assertSame($storeB['id'], (int) $emp->jobInformation->fresh()->store_id);
    }

    public function test_kepala_toko_is_scoped_to_own_store_while_hr_sees_all_locations(): void
    {
        Sanctum::actingAs($this->hr);

        $store1 = Store::find($this->postJson('/api/v1/stores', [
            'name' => 'Toys City Store 1',
            'brand' => 'Toys City',
            'city' => 'Jakarta',
        ])->assertStatus(201)->json('data.id'));

        $store2 = Store::find($this->postJson('/api/v1/stores', [
            'name' => 'Toys City Store 2',
            'brand' => 'Toys City',
            'city' => 'Bandung',
        ])->assertStatus(201)->json('data.id'));

        $team1Cashier = Team::where('store_id', $store1->id)->where('name', 'Cashier')->firstOrFail();
        $team1Warehouse = Team::where('store_id', $store1->id)->where('name', 'Warehouse')->firstOrFail();
        $team2Cashier = Team::where('store_id', $store2->id)->where('name', 'Cashier')->firstOrFail();

        $kepalaTokoProfile = $this->createEmployeeAtStore('kt1@example.com', $store1, null, 'manager');
        $store1->update(['head_user_id' => $kepalaTokoProfile->user_id]);

        $empStore1 = $this->createEmployeeAtStore('staff1@example.com', $store1, $team1Cashier);
        $empStore1Wh = $this->createEmployeeAtStore('wh1@example.com', $store1, $team1Warehouse);
        $empStore2 = $this->createEmployeeAtStore('staff2@example.com', $store2, $team2Cashier);

        Attendance::create([
            'employee_id' => $empStore1->id,
            'date' => now()->toDateString(),
            'check_in' => now()->setTime(9, 0),
            'status' => 'present',
        ]);
        Attendance::create([
            'employee_id' => $empStore2->id,
            'date' => now()->toDateString(),
            'check_in' => now()->setTime(9, 0),
            'status' => 'present',
        ]);

        $leaveType = \App\Enums\LeaveType::cases()[0]->value;
        $leave1 = LeaveRequest::create([
            'employee_id' => $empStore1->id,
            'leave_type' => $leaveType,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'total_days' => 2,
            'reason' => 'Family',
            'status' => 'pending',
        ]);
        $leave2 = LeaveRequest::create([
            'employee_id' => $empStore2->id,
            'leave_type' => $leaveType,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'total_days' => 2,
            'reason' => 'Personal',
            'status' => 'pending',
        ]);

        // Act as Kepala Toko of Store 1
        Sanctum::actingAs($kepalaTokoProfile->user);

        // Sees all teams in Store 1 (6 teams), NONE from Store 2
        $teamsRes = $this->getJson('/api/v1/teams')->assertStatus(200);
        $teamStoreIds = collect($teamsRes->json('data'))->pluck('store_id')->unique()->values()->all();
        $this->assertSame([$store1->id], $teamStoreIds);
        $this->assertCount(6, $teamsRes->json('data'));

        // Cannot view team in Store 2
        $this->getJson("/api/v1/teams/{$team2Cashier->id}")->assertStatus(403);

        // Sees employees in Store 1 across all teams, NOT Store 2
        $empRes = $this->getJson('/api/v1/employees')->assertStatus(200);
        $visibleEmpIds = collect($empRes->json('data'))->pluck('id')->all();
        $this->assertContains($empStore1->id, $visibleEmpIds);
        $this->assertContains($empStore1Wh->id, $visibleEmpIds);
        $this->assertNotContains($empStore2->id, $visibleEmpIds);

        // Can approve leave request in Store 1, FORBIDDEN on Store 2
        $this->postJson("/api/v1/leave-requests/approve/{$leave1->id}")->assertStatus(200);
        $this->postJson("/api/v1/leave-requests/approve/{$leave2->id}")->assertStatus(403);

        // Attendance list scoped to Store 1 only
        $attRes = $this->getJson('/api/v1/attendances')->assertStatus(200);
        $attEmpIds = collect($attRes->json('data'))->pluck('employee.id')->all();
        $this->assertContains($empStore1->id, $attEmpIds);
        $this->assertNotContains($empStore2->id, $attEmpIds);

        // HR still sees ALL employees across all stores
        Sanctum::actingAs($this->hr);
        $hrEmpIds = collect($this->getJson('/api/v1/employees')->assertStatus(200)->json('data'))->pluck('id')->all();
        $this->assertContains($empStore1->id, $hrEmpIds);
        $this->assertContains($empStore2->id, $hrEmpIds);
    }

    public function test_svg_upload_is_rejected_for_team_icon(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->hr);

        $ho = Store::where('type', Store::TYPE_HEAD_OFFICE)->firstOrFail();
        $svg = UploadedFile::fake()->createWithContent('xss.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        // Create team with SVG -> rejected 422
        $this->post('/api/v1/teams', [
            'store_id' => $ho->id,
            'name' => 'SVG Test Team',
            'icon' => $svg,
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['icon']);

        // Update team with SVG -> rejected 422
        $team = Team::create([
            'store_id' => $ho->id,
            'name' => 'Valid Team',
            'status' => 'active',
        ]);

        $this->post("/api/v1/teams/{$team->id}", [
            '_method' => 'PUT',
            'name' => 'Valid Team',
            'icon' => $svg,
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['icon']);
    }
}
