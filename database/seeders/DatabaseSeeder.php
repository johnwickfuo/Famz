<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            SettingsSeeder::class,
            SpecialisationSeeder::class,
            // Published feeding tables. Reference data, not demonstration data:
            // the assistant quotes from these on a real deployment, and without
            // them it has nothing to cite and falls back to saying so.
            BreedStandardSeeder::class,
            WorkerSkillSeeder::class,
            SuperAdminSeeder::class,
        ]);
    }
}
