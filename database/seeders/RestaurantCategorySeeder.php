<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RestaurantCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Beverage',
                'icon' => 'beverage.png', // or SVG / FontAwesome string like 'fa-glass-cheers'
            ],
            [
                'name' => 'Khmer Food',
                'icon' => 'khmer-food.png',
            ],
            [
                'name' => 'Fast Food',
                'icon' => 'fast-food.png',
            ],
            [
                'name' => 'Hotpot & BBQ',
                'icon' => 'hotpot-bbq.png',
            ],
            [
                'name' => 'Asian',
                'icon' => 'asian.png',
            ],
            [
                'name' => 'Western',
                'icon' => 'western.png',
            ],
        ];
        foreach ($categories as $category) {
            DB::table('restaurant_categories')->updateOrInsert(
                ['name' => $category['name']], // Match by name to prevent duplicates
                [
                    'icon' => $category['icon'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
