<?php

namespace Database\Seeders;

use App\Models\Reward;
use Illuminate\Database\Seeder;

class RewardSeeder extends Seeder
{
    public function run(): void
    {
        $rewards = [
            [
                'name'            => 'Mochila Ergonómica Campus',
                'description'     => 'Mochila impermeable con compartimento acolchado para laptop de hasta 16" y logotipo bordado.',
                'points_required' => 450,
                'stock'           => 30,
                'reserved_stock'  => 0,
                'is_physical'     => true,
                'status'          => 'active',
                'business_id'     => 'biz-souvenirs',
            ],
            [
                'name'            => 'Termo Térmico de Acero 750ml',
                'description'     => 'Mantiene bebidas frías por 24h y calientes por 12h. Acabado mate con grabado láser.',
                'points_required' => 220,
                'stock'           => 50,
                'reserved_stock'  => 0,
                'is_physical'     => true,
                'status'          => 'active',
                'business_id'     => 'biz-souvenirs',
            ],
            [
                'name'            => 'Boleto de Descuento 50% Cafetería',
                'description'     => 'Válido para consumo de alimentos y bebidas en cualquiera de las sedes de la cafetería central.',
                'points_required' => 120,
                'stock'           => 200,
                'reserved_stock'  => 0,
                'is_physical'     => false,
                'status'          => 'active',
                'business_id'     => 'biz-cafeteria-central',
            ],
            [
                'name'            => 'Hoodie Universitario Oficial',
                'description'     => 'Sudadera con capucha algodón premium, interior afelpado y escudo universitario de edición especial.',
                'points_required' => 600,
                'stock'           => 25,
                'reserved_stock'  => 0,
                'is_physical'     => true,
                'status'          => 'active',
                'business_id'     => 'biz-souvenirs',
            ],
            [
                'name'            => 'Cuaderno Ejecutivo y Bolígrafo de Metal',
                'description'     => 'Cuaderno pasta dura con hojas punteadas de 100g, elástico portalápiz y bolígrafo con tinta gel.',
                'points_required' => 180,
                'stock'           => 60,
                'reserved_stock'  => 0,
                'is_physical'     => true,
                'status'          => 'active',
                'business_id'     => 'biz-libreria',
            ],
            [
                'name'            => 'Impresiones y Copias (Bono 100 Páginas)',
                'description'     => 'Bono digital redimible en centro de copiado para impresiones a color y b/n.',
                'points_required' => 80,
                'stock'           => 300,
                'reserved_stock'  => 0,
                'is_physical'     => false,
                'status'          => 'active',
                'business_id'     => 'biz-papeleria',
            ],
        ];

        foreach ($rewards as $r) {
            Reward::firstOrCreate(
                ['name' => $r['name']],
                $r
            );
        }
    }
}
