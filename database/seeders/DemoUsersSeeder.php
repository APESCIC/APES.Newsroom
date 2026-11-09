<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use App\Support\DemoAccounts;
use Illuminate\Database\Seeder;

class DemoUsersSeeder extends Seeder
{
    /**
     * Seed password-based demo users for local role preview.
     *
     * All accounts use DemoAccounts::PASSWORD. Role is force-filled because
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
                'email' => DemoAccounts::MEMBER,
                'role' => Role::Public,
            ],
            [
                'name' => 'Staff Demo',
                'email' => DemoAccounts::STAFF,
                'role' => Role::Staff,
            ],
            [
                'name' => 'Admin Demo',
                'email' => DemoAccounts::ADMIN,
                'role' => Role::Admin,
            ],
        ];

        foreach ($users as $data) {
            $user = User::query()->updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => DemoAccounts::PASSWORD,
                    'email_verified_at' => now(),
                    'auth_provider' => 'password',
                ],
            );

            $user->forceFill(['role' => $data['role']])->save();
        }
    }
}
