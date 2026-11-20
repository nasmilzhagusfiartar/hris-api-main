<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\Concerns\RequiresSeederPassword;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    use RequiresSeederPassword;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaultPassword = $this->resolveSeederPassword();

        $superAdmin = User::firstOrCreate(
            ['email' => 'superadmin@gmail.com'],
            [
                'name' => 'Super Admin',
                'password' => bcrypt($defaultPassword),
                'profile_photo' => 'profile-pictures/male/1.avif',
            ]
        );

        $superAdmin->syncRoles(['super-admin']);
    }
}
