<?php

namespace Database\Seeders;

use App\Models\EmployeeProfile;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Database\Seeders\Concerns\RequiresSeederPassword;
use Illuminate\Database\Seeder;

class ManagerSeeder extends Seeder
{
    use RequiresSeederPassword;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaultPassword = $this->resolveSeederPassword();

        $manager = User::firstOrCreate(
            ['email' => 'manager@gmail.com'],
            [
                'name' => 'Manager',
                'password' => bcrypt($defaultPassword),
                'profile_photo' => 'profile-pictures/male/1.avif',
            ]
        );

        $manager->syncRoles(['manager']);

        $profile = $manager->employeeProfile()->firstOrCreate(
            ['user_id' => $manager->id],
            [
                'code' => 'MGR001',
                'identity_number' => '3171000000000101',
                'phone' => '081210000101',
                'date_of_birth' => '1990-03-15',
                'gender' => 'male',
                'place_of_birth' => 'Jakarta',
                'address' => 'Jl. Jenderal Sudirman No. 12',
                'city' => 'Jakarta',
                'postal_code' => '10220',
            ]
        );

        // Ensure Manager leads at least one operational team (PT Hero Intiputra retail context)
        $team = Team::firstOrCreate(
            ['name' => 'Retail Sales & Store Operations'],
            [
                'expected_size' => 10,
                'description' => 'Retail sales and store operations team for PT Hero Intiputra.',
                'icon' => 'store',
                'department' => 'sales',
                'status' => 'active',
                'team_lead_id' => $manager->id,
                'responsibilities' => [
                    'Manage daily retail sales operations',
                    'Coordinate store staff scheduling and attendance',
                    'Review team leave requests and performance reports',
                ],
            ]
        );

        if (! $team->team_lead_id) {
            $team->update(['team_lead_id' => $manager->id]);
        }

        $profile->jobInformation()->firstOrCreate(
            ['employee_id' => $profile->id],
            [
                'job_title' => 'Operations Manager',
                'team_id' => $team->id,
                'years_experience' => 8,
                'status' => 'active',
                'employment_type' => 'full_time',
                'work_location' => 'office',
                'start_date' => '2024-01-01',
                'monthly_salary' => 18000000,
                'skill_level' => 'expert',
            ]
        );

        if (! $profile->jobInformation->team_id) {
            $profile->jobInformation->update(['team_id' => $team->id]);
        }

        $profile->bankInformation()->firstOrCreate(
            ['employee_id' => $profile->id],
            [
                'bank_name' => 'bca',
                'account_number' => '5550011223',
                'account_holder_name' => $manager->name,
                'account_type' => 'saving',
            ]
        );

        $profile->emergencyContacts()->firstOrCreate(
            ['employee_id' => $profile->id, 'email' => 'manager.emergency@gmail.com'],
            [
                'full_name' => 'Manager Emergency Contact',
                'phone' => '081210000102',
                'relationship' => 'Family',
            ]
        );

        // Register manager as active member of the team
        TeamMember::firstOrCreate(
            [
                'team_id' => $team->id,
                'employee_id' => $profile->id,
            ],
            [
                'joined_at' => now(),
                'left_at' => null,
            ]
        );

        // If default employee exists and has no active team yet, assign them to the manager's team
        $defaultEmployee = User::where('email', 'employee@gmail.com')->first();
        if ($defaultEmployee?->employeeProfile) {
            $empProfile = $defaultEmployee->employeeProfile;
            $hasActiveMembership = TeamMember::where('employee_id', $empProfile->id)
                ->whereNull('left_at')
                ->exists();

            if (! $hasActiveMembership) {
                TeamMember::firstOrCreate(
                    [
                        'team_id' => $team->id,
                        'employee_id' => $empProfile->id,
                    ],
                    [
                        'joined_at' => now(),
                        'left_at' => null,
                    ]
                );

                if ($empProfile->jobInformation && ! $empProfile->jobInformation->team_id) {
                    $empProfile->jobInformation->update(['team_id' => $team->id]);
                }
            }
        }
    }
}
