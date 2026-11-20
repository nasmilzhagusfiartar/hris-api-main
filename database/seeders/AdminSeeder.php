<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\Concerns\RequiresSeederPassword;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    use RequiresSeederPassword;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaultPassword = $this->resolveSeederPassword();

        $adminUser = User::firstOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'name' => 'Administrator',
                'password' => bcrypt($defaultPassword),
                'profile_photo' => 'profile-pictures/male/1.avif',
            ]
        );

        $profile = $adminUser->employeeProfile()->firstOrCreate(
            ['user_id' => $adminUser->id],
            [
                'code' => 'ADM001',
                'identity_number' => '3171000000000001',
                'phone' => '081200000001',
                'date_of_birth' => '1992-01-10',
                'gender' => 'male',
                'place_of_birth' => 'Jakarta',
                'address' => 'Jl. Jenderal Sudirman No. 1',
                'city' => 'Jakarta',
                'postal_code' => '10220',
            ]
        );

        $profile->jobInformation()->firstOrCreate(
            ['employee_id' => $profile->id],
            [
                'job_title' => 'System Administrator',
                'years_experience' => 6,
                'status' => 'active',
                'employment_type' => 'full_time',
                'work_location' => 'office',
                'start_date' => '2024-01-01',
                'monthly_salary' => 15000000,
                'skill_level' => 'expert',
            ]
        );

        $profile->bankInformation()->firstOrCreate(
            ['employee_id' => $profile->id],
            [
                'bank_name' => 'bca',
                'account_number' => '7770011223',
                'account_holder_name' => 'Administrator',
                'account_type' => 'saving',
            ]
        );

        $profile->emergencyContacts()->firstOrCreate(
            ['employee_id' => $profile->id, 'email' => 'admin.emergency@gmail.com'],
            [
                'full_name' => 'Admin Emergency Contact',
                'phone' => '081200000002',
                'relationship' => 'Family',
            ]
        );

        $adminUser->syncRoles(['admin']);
    }
}
