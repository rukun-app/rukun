<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Modules\Billing\Database\Seeders\BillingSeeder;
use Modules\Civic\Database\Seeders\CivicSeeder;
use Modules\Community\Database\Seeders\CommunitySeeder;
use Modules\Wifi\Database\Seeders\WifiSeeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([RbacSeeder::class, CommunitySeeder::class, BillingSeeder::class, WifiSeeder::class, CivicSeeder::class, \Modules\Engagement\Database\Seeders\EngagementSeeder::class]);
    }
}
