<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        $this->call([
            ProductsTableSeeder::class,
            WarehousesTableSeeder::class,
            StocksTableSeeder::class,
        ]);
    }
}

