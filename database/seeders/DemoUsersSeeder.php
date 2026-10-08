<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoUsersSeeder extends Seeder
{
    /**
     * Seed password-based demo users for local role preview.
     *
     * All accounts use password "password". Role is force-filled because
     * it is not mass-assignable on User. Refuses to run outside local and
     * testing, even when called directly with --class.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('DemoUsersSeeder skipped: demo accounts are only seeded in local and testing.');

            return;
        }

        $users = [
            [
                'name' => 'Public Demo',
                'email' => 'public@apes.local',
                'role' => Role::Public,
            ],
            [
                'name' => 'Staff Demo',
                'email' => 'staff@apes.local',
                'role' => Role::Staff,
            ],
            [
                'name' => 'Admin Demo',
                'email' => 'admin@apes.local',
                'role' => Role::Admin,
            ],
            [
                'name' => 'Super Admin Demo',
                'email' => 'superadmin@apes.local',
                'role' => Role::SuperAdmin,
            ],
        ];

        foreach ($users as $data) {
            $user = User::query()->updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => 'password',
                    'email_verified_at' => now(),
                    'auth_provider' => 'password',
                ],
            );

            $user->forceFill(['role' => $data['role']])->save();
        }
    }
}
