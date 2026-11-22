<?php

namespace Tests\Feature;

use App\Models\EmployeeProfile;
use App\Models\JobInformation;
use App\Models\Project;
use App\Models\Store;
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

class ImageUploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Store $headOffice;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::factory()->create(['email' => 'sec-upload@example.com', 'is_active' => true]);
        $this->admin->syncRoles(['super-admin']);

        $this->headOffice = Store::where('type', Store::TYPE_HEAD_OFFICE)->first()
            ?? Store::create([
                'code' => 'HO-01',
                'type' => Store::TYPE_HEAD_OFFICE,
                'name' => 'Head Office',
                'brand' => 'PT Hero Intiputra',
                'city' => 'Jakarta',
                'is_active' => true,
            ]);
    }

    private function fakeSvg(string $name = 'malicious.svg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert("xss")</script></svg>'
        );
    }

    private function fakePhpDisguisedAsJpg(string $name = 'shell.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            '<?php system($_GET["cmd"]); ?>'
        );
    }

    public function test_profile_photo_rejects_svg_and_php_disguised_as_jpg(): void
    {
        Sanctum::actingAs($this->admin);

        foreach ([$this->fakeSvg(), $this->fakePhpDisguisedAsJpg()] as $file) {
            $this->post('/api/v1/me', [
                '_method' => 'PUT',
                'name' => 'Admin Updated',
                'profile_photo' => $file,
            ], ['Accept' => 'application/json'])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['profile_photo']);
        }
    }

    public function test_employee_create_and_update_reject_svg_and_php_disguised_as_jpg(): void
    {
        Sanctum::actingAs($this->admin);

        $basePayload = [
            'name' => 'Upload Test Emp',
            'email' => 'upload-emp@example.com',
            'password' => 'Secret123!',
            'roles' => ['employee'],
            'identity_number' => '3171000012345678',
            'phone' => '081234567890',
            'date_of_birth' => '1995-05-05',
            'gender' => 'male',
            'place_of_birth' => 'Jakarta',
            'address' => 'Jl. Test',
            'city' => 'Jakarta',
            'postal_code' => '10110',
            'job_title' => 'Staff',
            'store_id' => $this->headOffice->id,
            'years_experience' => 2,
            'status' => 'active',
            'employment_type' => 'full_time',
            'work_location' => 'office',
            'start_date' => now()->toDateString(),
            'monthly_salary' => 6000000,
            'skill_level' => 'intermediate',
            'bank_name' => 'bca',
            'account_number' => '9988776655',
            'account_holder_name' => 'Upload Test Emp',
            'emergency_contacts' => [
                ['full_name' => 'EC', 'relationship' => 'sibling', 'phone' => '081200001111'],
            ],
            'educations' => [
                ['level' => 's1', 'institution' => 'UI', 'start_year' => 2015, 'end_year' => 2019, 'is_highest' => true],
            ],
        ];

        foreach ([$this->fakeSvg(), $this->fakePhpDisguisedAsJpg()] as $file) {
            $this->post('/api/v1/employees', array_merge($basePayload, [
                'profile_photo' => $file,
            ]), ['Accept' => 'application/json'])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['profile_photo']);
        }

        // Create valid employee then test update upload
        $empUser = User::factory()->create(['email' => 'existing-upload@example.com', 'is_active' => true]);
        $emp = EmployeeProfile::create([
            'user_id' => $empUser->id,
            'code' => 'EMP2026118888',
            'identity_number' => '3171888899990000',
            'phone' => '081288889999',
            'date_of_birth' => '1994-01-01',
            'gender' => 'male',
            'place_of_birth' => 'Jakarta',
            'address' => 'Jl. Test',
            'city' => 'Jakarta',
            'postal_code' => '10110',
        ]);

        foreach ([$this->fakeSvg(), $this->fakePhpDisguisedAsJpg()] as $file) {
            $this->post("/api/v1/employees/{$emp->id}", [
                '_method' => 'PUT',
                'name' => 'Updated Name',
                'profile_photo' => $file,
            ], ['Accept' => 'application/json'])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['profile_photo']);
        }
    }

    public function test_team_create_and_update_reject_svg_and_php_disguised_as_jpg(): void
    {
        Sanctum::actingAs($this->admin);

        foreach ([$this->fakeSvg(), $this->fakePhpDisguisedAsJpg()] as $file) {
            $this->post('/api/v1/teams', [
                'store_id' => $this->headOffice->id,
                'name' => 'Upload Sec Team',
                'icon' => $file,
            ], ['Accept' => 'application/json'])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['icon']);
        }

        $team = Team::create([
            'store_id' => $this->headOffice->id,
            'name' => 'Existing Sec Team',
            'status' => 'active',
        ]);

        foreach ([$this->fakeSvg(), $this->fakePhpDisguisedAsJpg()] as $file) {
            $this->post("/api/v1/teams/{$team->id}", [
                '_method' => 'PUT',
                'name' => 'Existing Sec Team',
                'icon' => $file,
            ], ['Accept' => 'application/json'])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['icon']);
        }
    }

    public function test_project_create_and_update_reject_svg_and_php_disguised_as_jpg(): void
    {
        Sanctum::actingAs($this->admin);

        $leaderUser = User::factory()->create(['email' => 'proj-leader@example.com', 'is_active' => true]);
        $leader = EmployeeProfile::create([
            'user_id' => $leaderUser->id,
            'code' => 'EMP2026117777',
            'identity_number' => '3171777766665555',
            'phone' => '081277776666',
            'date_of_birth' => '1993-01-01',
            'gender' => 'male',
            'place_of_birth' => 'Jakarta',
            'address' => 'Jl. Test',
            'city' => 'Jakarta',
            'postal_code' => '10110',
        ]);
        JobInformation::create([
            'employee_id' => $leader->id,
            'job_title' => 'Lead',
            'store_id' => $this->headOffice->id,
            'years_experience' => 3,
            'status' => 'active',
            'employment_type' => 'full_time',
            'work_location' => 'office',
            'start_date' => now()->subMonth()->toDateString(),
            'monthly_salary' => 8000000,
            'skill_level' => 'senior',
        ]);

        foreach ([$this->fakeSvg(), $this->fakePhpDisguisedAsJpg()] as $file) {
            $this->post('/api/v1/projects', [
                'name' => 'Sec Project',
                'type' => 'web_development',
                'priority' => 'high',
                'status' => 'active',
                'start_date' => now()->toDateString(),
                'end_date' => now()->addMonth()->toDateString(),
                'project_leader_id' => $leader->id,
                'photo' => $file,
            ], ['Accept' => 'application/json'])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['photo']);
        }

        $project = Project::create([
            'name' => 'Existing Sec Project',
            'type' => 'web_development',
            'priority' => 'medium',
            'status' => 'active',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'project_leader_id' => $leader->id,
        ]);

        foreach ([$this->fakeSvg(), $this->fakePhpDisguisedAsJpg()] as $file) {
            $this->post("/api/v1/projects/{$project->id}", [
                '_method' => 'PUT',
                'name' => 'Existing Sec Project',
                'photo' => $file,
            ], ['Accept' => 'application/json'])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['photo']);
        }
    }
}
