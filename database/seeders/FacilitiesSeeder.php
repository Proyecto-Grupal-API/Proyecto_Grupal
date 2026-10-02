<?php

namespace Database\Seeders;

use App\Models\StudentServices\Reservations\Facility;
use Illuminate\Database\Seeder;

class FacilitiesSeeder extends Seeder
{
    public function run(): void
    {
        $facilities = [
            [
                'name' => 'Sala de estudio 3',
                'type' => 'Sala',
                'building' => 'Edificio B',
                'capacity' => 2,
                'cost_cents' => 0,
                'active' => true,
            ],
            [
                'name' => 'Laboratorio de Redes',
                'type' => 'Laboratorio',
                'building' => 'Edificio C',
                'capacity' => 15,
                'cost_cents' => 0,
                'active' => true,
            ],
            [
                'name' => 'Auditorio Principal',
                'type' => 'Auditorio',
                'building' => 'Edificio A',
                'capacity' => 80,
                'cost_cents' => 15000,
                'active' => true,
            ],
            [
                'name' => 'Cancha techada',
                'type' => 'Cancha',
                'building' => 'Zona deportiva',
                'capacity' => 10,
                'cost_cents' => 5000,
                'active' => true,
            ],
        ];

        foreach ($facilities as $facility) {
            Facility::query()->firstOrCreate(
                ['name' => $facility['name']],
                $facility
            );
        }
    }
}
