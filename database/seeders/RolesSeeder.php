<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('roles')->insertOrIgnore([
            ['name' => 'super_admin', 'display_name' => 'Super Admin', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'organizer',   'display_name' => 'Organizer',   'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
