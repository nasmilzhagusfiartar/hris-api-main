<?php

namespace Tests\Feature;

use App\Enums\WorkLocation;
use App\Models\BankInformation;
use App\Models\EmployeeProfile;
use App\Models\JobInformation;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeEducationEventBankTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->hr = User::factory()->create(['email' => 'hr-stage2@example.com', 'is_active' => true]);
        $this->hr->syncRoles(['hr']);
    }

    private function baseEmployeePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Budi Santoso',
            'email' => 'budi.stage2@example.com',
            'password' => 'Secret123!',
            'roles' => ['employee'],
            'identity_number' => '3171000011112222',
            'phone' => '081234567890',
            'date_of_birth' => '1995-05-05',
            'gender' => 'male',
            'place_of_birth' => 'Jakarta',
            'address' => 'Jl. Merdeka No. 10',
            'city' => 'Jakarta',
            'postal_code' => '10110',
            'job_title' => 'Event Staff',
            'department' => 'sales',
            'years_experience' => 2,
            'status' => 'active',
            'employment_type' => 'full_time',
            'work_location' => 'event',
            'start_date' => now()->toDateString(),
            'monthly_salary' => 6000000,
            'skill_level' => 'intermediate',
            'bank_name' => 'bca',
            'account_number' => '9876543210',
            'account_holder_name' => 'Budi Santoso',
            'emergency_contacts' => [
                [
                    'full_name' => 'Siti Santoso',
                    'relationship' => 'sibling',
                    'phone' => '081299998888',
                ],
            ],
            'educations' => [
                [
                    'level' => 'sma_smk',
                    'institution' => 'SMAN 1 Jakarta',
                    'major' => 'IPA',
                    'start_year' => 2010,
                    'end_year' => 2013,
                    'gpa' => null,
                    'is_highest' => false,
                ],
                [
                    'level' => 's1',
                    'institution' => 'Universitas Indonesia',
                    'major' => 'Management',
                    'start_year' => 2013,
                    'end_year' => 2017,
                    'gpa' => 3.75,
                    'is_highest' => true,
                ],
            ],
        ], $overrides);
    }

    public function test_create_update_and_view_employee_with_educations_event_location_and_simplified_bank(): void
    {
        Sanctum::actingAs($this->hr);

        // 1. Create without account_type, with work_location = 'event' and 2 educations
        $createRes = $this->postJson('/api/v1/employees', $this->baseEmployeePayload())
            ->assertStatus(201)
            ->assertJsonPath('data.job_information.work_location', 'event')
            ->assertJsonPath('data.bank_information.account_holder_name', 'Budi Santoso')
            ->assertJsonPath('data.bank_information.account_type', null);

        $employeeId = $createRes->json('data.id');
        $educations = $createRes->json('data.educations');
        $this->assertCount(2, $educations);
        $this->assertTrue($educations[0]['is_highest']);
        $this->assertSame('s1', $educations[0]['level']);

        // 2. Update: remove SMA, keep S1 (updated), add S2 as new highest
        $s1Id = collect($educations)->firstWhere('level', 's1')['id'];
        $updateRes = $this->putJson("/api/v1/employees/{$employeeId}", [
            'educations' => [
                [
                    'id' => $s1Id,
                    'level' => 's1',
                    'institution' => 'Universitas Indonesia',
                    'major' => 'Business Management',
                    'start_year' => 2013,
                    'end_year' => 2017,
                    'gpa' => 3.80,
                    'is_highest' => false,
                ],
                [
                    'level' => 's2',
                    'institution' => 'Institut Teknologi Bandung',
                    'major' => 'MBA',
                    'start_year' => 2018,
                    'end_year' => 2020,
                    'gpa' => 3.90,
                    'is_highest' => true,
                ],
            ],
        ])->assertStatus(200);

        $updatedEducations = $updateRes->json('data.educations');
        $this->assertCount(2, $updatedEducations);
        $this->assertSame('s2', $updatedEducations[0]['level']);
        $this->assertTrue($updatedEducations[0]['is_highest']);

        // Verify removed SMA was soft-deleted
        $this->assertSoftDeleted('employee_educations', [
            'employee_id' => $employeeId,
            'level' => 'sma_smk',
        ]);

        // 3. Employee can view own educations via /my-profile
        $employeeUser = User::where('email', 'budi.stage2@example.com')->firstOrFail();
        Sanctum::actingAs($employeeUser);
        $this->getJson('/api/v1/my-profile')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.educations')
            ->assertJsonPath('data.educations.0.level', 's2');
    }

    public function test_education_validation_rules(): void
    {
        Sanctum::actingAs($this->hr);
        $nextYear = (int) date('Y') + 1;

        // Missing educations
        $this->postJson('/api/v1/employees', $this->baseEmployeePayload(['educations' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['educations']);

        // No highest education marked
        $this->postJson('/api/v1/employees', $this->baseEmployeePayload([
            'educations' => [
                [
                    'level' => 's1',
                    'institution' => 'UI',
                    'start_year' => 2015,
                    'end_year' => 2019,
                    'is_highest' => false,
                ],
            ],
        ]))->assertStatus(422)->assertJsonValidationErrors(['educations']);

        // Multiple highest educations marked
        $this->postJson('/api/v1/employees', $this->baseEmployeePayload([
            'educations' => [
                ['level' => 'sma_smk', 'institution' => 'SMA 1', 'start_year' => 2012, 'end_year' => 2015, 'is_highest' => true],
                ['level' => 's1', 'institution' => 'UI', 'start_year' => 2015, 'end_year' => 2019, 'is_highest' => true],
            ],
        ]))->assertStatus(422)->assertJsonValidationErrors(['educations']);

        // end_year < start_year, future year, and GPA > 4
        $this->postJson('/api/v1/employees', $this->baseEmployeePayload([
            'educations' => [
                [
                    'level' => 's1',
                    'institution' => 'UI',
                    'start_year' => $nextYear,
                    'end_year' => 2015,
                    'gpa' => 4.5,
                    'is_highest' => true,
                ],
            ],
        ]))->assertStatus(422)->assertJsonValidationErrors([
            'educations.0.start_year',
            'educations.0.end_year',
            'educations.0.gpa',
        ]);
    }

    public function test_work_location_enum_uses_event_and_rejects_hybrid_and_data_migration_converts_hybrid(): void
    {
        $this->assertSame('event', WorkLocation::EVENT->value);
        $this->assertSame('Event', WorkLocation::EVENT->label());
        $this->assertNotContains('hybrid', array_column(WorkLocation::cases(), 'value'));

        Sanctum::actingAs($this->hr);

        // Reject 'hybrid' in validation
        $this->postJson('/api/v1/employees', $this->baseEmployeePayload(['work_location' => 'hybrid']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['work_location']);

        // Verify data migration converts legacy 'hybrid' rows to 'event'
        $user = User::factory()->create(['email' => 'legacy-hybrid@example.com']);
        $profile = EmployeeProfile::create([
            'user_id' => $user->id,
            'code' => 'EMP2026119999',
            'identity_number' => '3171999988887777',
            'phone' => '081200001111',
            'date_of_birth' => '1992-01-01',
            'gender' => 'male',
            'place_of_birth' => 'Jakarta',
            'address' => 'Jl. Test',
            'city' => 'Jakarta',
            'postal_code' => '10110',
        ]);

        DB::table('job_information')->insert([
            'employee_id' => $profile->id,
            'job_title' => 'Legacy Hybrid',
            'years_experience' => 1,
            'status' => 'active',
            'employment_type' => 'full_time',
            'work_location' => 'hybrid',
            'start_date' => '2025-01-01',
            'monthly_salary' => 5000000,
            'skill_level' => 'beginner',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_11_22_000002_migrate_hybrid_work_location_to_event.php');
        $migration->up();

        $this->assertSame('event', JobInformation::where('employee_id', $profile->id)->value('work_location'));

        // Verify bank_information allows null account_type
        $bank = BankInformation::create([
            'employee_id' => $profile->id,
            'bank_name' => 'mandiri',
            'account_number' => '1122334455',
            'account_holder_name' => 'Legacy Hybrid',
            'account_type' => null,
        ]);
        $this->assertNull($bank->account_type);
    }
}
