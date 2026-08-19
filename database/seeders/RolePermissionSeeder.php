<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Spatie\Permission\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Role::create([
        'name' => 'Coach',
        'guard_name' => 'web',
        ]);

        Role::create([
        'name' => 'Team Leader',
        'guard_name' => 'web',
        ]);

        Role::create([
        'name' => 'Player',
        'guard_name' => 'web',
        ]);
    }
}
