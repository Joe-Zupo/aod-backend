<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (['Coach', 'Team Leader', 'Player'] as $name) {
            Role::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }
    }
}
