<?php

namespace Database\Seeders;

use App\Models\User;
use App\Security\Roles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * One demo user per role ({role}@alquds.test / password "password") for local work and
 * screenshots. Refuses to run in production: those addresses and that password must never
 * exist there.
 */
class DemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DemoUsersSeeder never runs in production.');
        }

        foreach (Roles::ALL as $role) {
            $user = User::firstOrCreate(
                ['email' => "{$role}@alquds.test"],
                ['name' => Roles::label($role), 'password' => Hash::make('password'), 'status' => 'active'],
            );
            $user->syncRoles([$role]);
        }
    }
}
