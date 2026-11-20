<?php

namespace Database\Seeders;

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
    }
}
