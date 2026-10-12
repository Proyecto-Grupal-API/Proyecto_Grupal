<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\BusinessApplication;
use App\Models\BusinessMember;
use App\Models\Product;
use App\Models\Storefront;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('CAMPUS_ADMIN_EMAIL', 'admin@campus.local');
        $admin = User::where('email', $email)->first();

        if (!$admin) {
            $admin = User::create([
                'name' => env('CAMPUS_ADMIN_NAME', 'Administrador Campus'),
                'email' => $email,
                'password' => Hash::make(env('CAMPUS_ADMIN_PASSWORD', 'admin12345')),
                'role' => 'admin',
                'status' => 'active',
            ]);
        }

        $businesses = [
            [
                'name' => 'Tienda Institucional',
                'slug' => 'tienda-institucional',
                'type' => 'souvenirs',
                'description' => 'Productos oficiales del campus: playeras, termos, gorras y artículos de identidad.',
                'tags' => ['Oficial', 'Souvenirs', 'Campus'],
                'payment_methods' => ['wallet','bonus','points','spei','openpay'],
            ],
            [
                'name' => 'Campus Creativo',
                'slug' => 'campus-creativo',
                'type' => 'student',
                'description' => 'Diseño, impresión y productos personalizados de estudiantes autorizados.',
                'tags' => ['Estudiantes', 'Diseño', 'Impresión'],
                'payment_methods' => ['wallet','spei','openpay'],
            ],
            [
                'name' => 'Asociación STEM',
                'slug' => 'asociacion-stem',
                'type' => 'association',
                'description' => 'Productos y actividades de la asociación estudiantil STEM.',
                'tags' => ['Asociación', 'STEM', 'Eventos'],
                'payment_methods' => ['wallet','bonus','points'],
            ],
        ];

        foreach ($businesses as $data) {
            $business = Business::updateOrCreate(
                ['slug' => $data['slug']],
                array_merge($data, [
                    'owner_id' => (string) $admin->getKey(),
                    'status' => 'approved',
                    'visibility' => 'public',
                    'verified' => true,
                    'policies' => ['returns' => 'Solicitar devolución dentro de 7 días.', 'privacy' => 'Datos tratados por Campus Digital.'],
                    'hours' => ['mon-fri' => '08:00-18:00'],
                    'delivery_points' => ['Biblioteca Central', 'Edificio A - Recepción'],
                    'contact' => ['email' => 'comercio@campus.local'],
                ])
            );

            BusinessMember::updateOrCreate(
                ['business_id' => (string) $business->getKey(), 'user_id' => (string) $admin->getKey()],
                ['role' => 'owner', 'status' => 'active', 'scopes' => ['*']]
            );

            Storefront::updateOrCreate(
                ['business_id' => (string) $business->getKey()],
                [
                    'name' => $business->name,
                    'brand' => 'Campus Digital',
                    'description' => $business->description,
                    'published' => true,
                    'policies' => $business->policies,
                    'hours' => $business->hours,
                    'delivery_points' => $business->delivery_points,
                    'channels' => ['email'],
                ]
            );

            $products = match ($data['slug']) {
                'tienda-institucional' => [
                    ['name'=>'Playera Campus Digital', 'category'=>'Ropa', 'price'=>399, 'description'=>'Playera oficial de algodón con identidad Campus Digital.', 'stock'=>30],
                    ['name'=>'Termo Campus 750 ml', 'category'=>'Accesorios', 'price'=>289, 'description'=>'Termo reutilizable para uso diario en campus.', 'stock'=>24],
                    ['name'=>'Gorra Institucional', 'category'=>'Ropa', 'price'=>249, 'description'=>'Gorra bordada con logotipo institucional.', 'stock'=>18],
                    ['name'=>'Taza Campus Digital', 'category'=>'Hogar', 'price'=>179, 'description'=>'Taza cerámica con diseño institucional.', 'stock'=>40],
                ],
                'campus-creativo' => [
                    ['name'=>'Impresión 3D personalizada', 'category'=>'Servicios', 'price'=>120, 'description'=>'Servicio de impresión 3D por pieza y configuración.', 'stock'=>999],
                    ['name'=>'Diseño de presentación', 'category'=>'Diseño', 'price'=>250, 'description'=>'Diseño de presentación académica personalizada.', 'stock'=>999],
                    ['name'=>'Sticker pack Campus', 'category'=>'Papelería', 'price'=>85, 'description'=>'Pack de stickers resistentes para laptop y cuadernos.', 'stock'=>50],
                ],
                default => [
                    ['name'=>'Playera Asociación STEM', 'category'=>'Ropa', 'price'=>350, 'description'=>'Playera con identidad de la asociación STEM.', 'stock'=>20],
                    ['name'=>'Taza STEM', 'category'=>'Hogar', 'price'=>160, 'description'=>'Taza temática de la asociación.', 'stock'=>15],
                ],
            };

            foreach ($products as $product) {
                Product::updateOrCreate(
                    ['slug' => \Illuminate\Support\Str::slug($product['name']).'-'.$business->slug],
                    [
                        'business_id' => (string) $business->getKey(),
                        'name' => $product['name'],
                        'slug' => \Illuminate\Support\Str::slug($product['name']).'-'.$business->slug,
                        'kind' => str_contains(strtolower($product['category']), 'servicio') || str_contains(strtolower($product['name']), 'diseño') ? 'service' : 'product',
                        'category' => $product['category'],
                        'description' => $product['description'],
                        'price' => $product['price'],
                        'currency' => 'MXN',
                        'active' => true,
                        'available' => true,
                        'inventory_required' => !str_contains(strtolower($product['category']), 'servicio') && !str_contains(strtolower($product['name']), 'diseño'),
                        'stock_snapshot' => $product['stock'],
                        'variants' => [],
                        'attributes' => [],
                        'tags' => [$business->type],
                    ]
                );
            }
        }

        $this->call(MongoIndexesSeeder::class);

        BusinessApplication::updateOrCreate(
            ['business_name' => 'Demo Negocio en revisión', 'applicant_id' => (string) $admin->getKey()],
            [
                'type' => 'student',
                'description' => 'Registro demo para mostrar el flujo de autorización.',
                'status' => 'pending',
                'observations' => 'Pendiente de revisión administrativa.',
                'submitted_at' => now(),
            ]
        );
    }
}

