<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\Concerns\RequiresSeederPassword;
use Illuminate\Database\Seeder;

class FinanceSeeder extends Seeder
{
    use RequiresSeederPassword;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaultPassword = $this->resolveSeederPassword();

        $employee = User::firstOrCreate(
            ['email' => 'finance@gmail.com'],
            [
                'name' => 'Finance',
                'password' => bcrypt($defaultPassword),
                'profile_photo' => 'profile-pictures/female/1.avif',
            ]
        );

        $employeeProfile = $employee->employeeProfile()->firstOrCreate(
            ['user_id' => $employee->id],
            [
                'code' => 'FIN001',
                'identity_number' => '333434141',
                'phone' => '081234567891',
                'date_of_birth' => '1995-05-15',
                'gender' => 'female',
                'place_of_birth' => 'Jakarta',
                'address' => 'Jl. Thamrin No. 5',
                'city' => 'Jakarta',
                'postal_code' => '10350',
            ]
        );

        $employeeProfile->jobInformation()->firstOrCreate(
            ['employee_id' => $employeeProfile->id],
            [
                'job_title' => 'Finance Manager',
                'years_experience' => 7,
                'status' => 'active',
                'employment_type' => 'full_time',
                'work_location' => 'office',
                'start_date' => '2024-01-01',
                'monthly_salary' => 12000000,
                'skill_level' => 'expert',
            ]
        );

        $employeeProfile->bankInformation()->firstOrCreate(
            ['employee_id' => $employeeProfile->id],
            [
                'bank_name' => 'BCA',
                'account_number' => '9876543210',
                'account_holder_name' => 'Finance',
                'account_type' => 'saving',
            ]
        );

        $employeeProfile->emergencyContacts()->firstOrCreate(
            ['employee_id' => $employeeProfile->id, 'email' => 'finance.emergency@gmail.com'],
            [
                'full_name' => 'Finance Emergency Contact',
                'phone' => '081234567891',
                'relationship' => 'Family',
            ]
        );

        $employee->syncRoles(['finance']);
    }
}
