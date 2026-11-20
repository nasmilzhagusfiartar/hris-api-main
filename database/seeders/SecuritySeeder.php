<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class SecuritySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaultPassword = env('SEEDER_DEFAULT_PASSWORD', 'password');

        $securityUser = User::firstOrCreate(
            ['email' => 'security@gmail.com'],
            [
                'name' => 'Security Officer',
                'password' => bcrypt($defaultPassword),
                'profile_photo' => 'profile-pictures/male/2.avif',
            ]
        );

        $profile = $securityUser->employeeProfile()->firstOrCreate(
            ['user_id' => $securityUser->id],
            [
                'code' => 'SEC001',
                'identity_number' => '3171000000000901',
                'phone' => '081299990001',
                'date_of_birth' => '1995-05-15',
                'gender' => 'male',
                'place_of_birth' => 'Jakarta',
                'address' => 'Jl. Gatot Subroto No. 10',
                'city' => 'Jakarta',
                'postal_code' => '12950',
            ]
        );

        $profile->jobInformation()->firstOrCreate(
            ['employee_id' => $profile->id],
            [
                'job_title' => 'Cyber Security Analyst',
                'years_experience' => 4,
                'status' => 'active',
                'employment_type' => 'full_time',
                'work_location' => 'office',
                'start_date' => '2024-01-01',
                'monthly_salary' => 12000000,
                'skill_level' => 'expert',
            ]
        );

        $profile->bankInformation()->firstOrCreate(
            ['employee_id' => $profile->id],
            [
                'bank_name' => 'bca',
                'account_number' => '8880011223',
                'account_holder_name' => 'Security Officer',
                'account_type' => 'saving',
            ]
        );

        $profile->emergencyContacts()->firstOrCreate(
            ['employee_id' => $profile->id, 'email' => 'security@gmail.com'],
            [
                'full_name' => 'Security Emergency Contact',
                'phone' => '081299990002',
                'relationship' => 'Family',
            ]
        );

        $securityUser->syncRoles(['security']);
    }
}
