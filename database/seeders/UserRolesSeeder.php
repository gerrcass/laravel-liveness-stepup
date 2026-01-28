<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Spatie\Permission\Models\Role;

class UserRolesSeeder extends Seeder
{
    public function run()
    {
        // Create roles
        $basic = Role::firstOrCreate(['name' => 'basic']);
        $priv = Role::firstOrCreate(['name' => 'privileged']);

        // Create or get users
        $u1 = User::firstOrCreate([
            'email' => 'basic@example.com'
        ], [
            'name' => 'Basic User',
            'password' => bcrypt('password'),
        ]);
        $u1->assignRole($basic);

        $u2 = User::firstOrCreate([
            'email' => 'priv@example.com'
        ], [
            'name' => 'Privileged User',
            'password' => bcrypt('password'),
        ]);
        $u2->assignRole($priv);
    }
}
