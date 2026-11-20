<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\Concerns\RequiresSeederPassword;
use Illuminate\Database\Seeder;

class EmployeeSeeder extends Seeder
{
    use RequiresSeederPassword;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaultPassword = $this->resolveSeederPassword();

        $employee = User::firstOrCreate(
            ['email' => 'employee@gmail.com'],
            [
                'name' => 'Employee',
                'password' => bcrypt($defaultPassword),
                'profile_photo' => 'profile-pictures/male/2.avif',
            ]
        );

        $employeeProfile = $employee->employeeProfile()->firstOrCreate(
            ['user_id' => $employee->id],
            [
                'code' => 'EMP001',
                'identity_number' => '1234567890',
                'phone' => '085325483259',
                'date_of_birth' => '2000-01-01',
                'gender' => 'male',
                'place_of_birth' => 'Jakarta',
                'address' => 'Jl. Sudirman No. 1',
                'city' => 'Jakarta',
                'postal_code' => '12345',
            ]
        );

        $employeeProfile->jobInformation()->firstOrCreate(
            ['employee_id' => $employeeProfile->id],
            [
                'job_title' => 'Software Engineer',
                'years_experience' => 5,
                'status' => 'active',
                'employment_type' => 'full_time',
                'work_location' => 'remote',
                'start_date' => '2024-01-01',
                'monthly_salary' => 10000000,
                'skill_level' => 'expert',
            ]
        );

        $employeeProfile->bankInformation()->firstOrCreate(
            ['employee_id' => $employeeProfile->id],
            [
                'bank_name' => 'BCA',
                'account_number' => '1234567890',
                'account_holder_name' => 'Employee',
                'account_type' => 'saving',
            ]
        );

        $employeeProfile->emergencyContacts()->firstOrCreate(
            ['employee_id' => $employeeProfile->id, 'email' => 'emergency@gmail.com'],
            [
                'full_name' => 'Emergency Contact',
                'phone' => '081234567890',
                'relationship' => 'Family',
            ]
        );

        $employee->syncRoles(['employee']);
    }
}
