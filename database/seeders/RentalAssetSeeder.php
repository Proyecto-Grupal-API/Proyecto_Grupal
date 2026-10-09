<?php

namespace Database\Seeders;

use App\Models\StudentServices\Rentals\Asset;
use Illuminate\Database\Seeder;

class RentalAssetSeeder extends Seeder
{
    public function run(): void
    {
        $assets = [
            [
                'inventory_item_id' => 'INV-E4-001',
                'name' => 'Laptop Dell Latitude',
                'category' => 'Computadoras',
                'location' => 'Centro de préstamo',
                'status' => 'available',
                'description' =>
                    'Laptop institucional para actividades académicas y prácticas.',
            ],
            [
                'inventory_item_id' => 'INV-E4-002',
                'name' => 'Proyector Epson',
                'category' => 'Proyección',
                'location' => 'Edificio B',
                'status' => 'available',
                'description' =>
                    'Proyector portátil para exposiciones y actividades académicas.',
            ],
            [
                'inventory_item_id' => 'INV-E4-003',
                'name' => 'Cámara Canon EOS',
                'category' => 'Fotografía',
                'location' => 'Laboratorio multimedia',
                'status' => 'rented',
                'description' =>
                    'Cámara digital para proyectos multimedia y producción audiovisual.',
            ],
            [
                'inventory_item_id' => 'INV-E4-004',
                'name' => 'Kit Arduino Uno',
                'category' => 'Electrónica',
                'location' => 'Laboratorio de electrónica',
                'status' => 'available',
                'description' =>
                    'Kit de desarrollo para prácticas de electrónica y programación.',
            ],
            [
                'inventory_item_id' => 'INV-E4-005',
                'name' => 'Micrófono USB',
                'category' => 'Multimedia',
                'location' => 'Laboratorio multimedia',
                'status' => 'maintenance',
                'description' =>
                    'Micrófono para grabación de audio y producción de contenido.',
            ],
            [
                'inventory_item_id' => 'INV-E4-006',
                'name' => 'Tablet Samsung Galaxy',
                'category' => 'Tablets',
                'location' => 'Centro de préstamo',
                'status' => 'available',
                'description' =>
                    'Tablet institucional para consulta de material y trabajo académico.',
            ],
        ];

        foreach ($assets as $asset) {
            Asset::updateOrCreate(
                [
                    'inventory_item_id' =>
                        $asset['inventory_item_id'],
                ],
                $asset
            );
        }
    }
}
