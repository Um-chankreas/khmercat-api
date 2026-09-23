<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RestaurantCategorySeeder::class,
            // RoleSeeder is not called here — it references App\Models\Role,
            // which doesn't exist anywhere in the codebase. Left in
            // database/seeders/ in case there was a real plan for it, but
            // running it just breaks `db:seed` for no benefit right now.
        ]);
    }
}
