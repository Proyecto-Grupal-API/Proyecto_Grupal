<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('campus:seed', function () {
    $this->call(\Database\Seeders\DatabaseSeeder::class);
})->purpose('Seed demo data for Campus Digital Equipo 3');
