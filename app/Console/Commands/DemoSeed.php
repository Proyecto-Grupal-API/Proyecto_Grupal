<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;

class DemoSeed extends Command
{
    protected $signature = 'demo:seed {--force : Sobrescribe stock aunque ya exista}';
    protected $description = 'Pobla la BD con datos de demostracion (productos, almacenes, stock, proveedores)';

    protected string $bid;

    public function handle(): int
    {
        $this->bid = (string) config('team4.business_id', 'BUS-CD-SOUV-001');
        $db = DB::connection('mongodb');

        // ------------------------------------------------------------
        // 1. Categorias
        // ------------------------------------------------------------
        $this->info('Categorias...');
        $cats = ['Souvenirs', 'Papeleria', 'Electronica', 'Accesorios', 'Ropa'];
        $catIds = [];
        foreach ($cats as $name) {
            $db->getCollection('categories')->updateOne(
                ['name' => $name, 'business_id' => $this->bid],
                [
                    '$set' => ['slug' => Str::slug($name)],
                    '$setOnInsert' => [
                        '_id' => new ObjectId(),
                        'name' => $name,
                        'business_id' => $this->bid,
                        'active' => true,
                        'created_at' => new \MongoDB\BSON\UTCDateTime(now()),
                    ],
                ],
                ['upsert' => true]
            );
            $c = $db->getCollection('categories')->findOne(['name' => $name, 'business_id' => $this->bid]);
            $catIds[$name] = (string) $c['_id'];
        }

        // ------------------------------------------------------------
        // 2. Almacenes
        // ------------------------------------------------------------
        $this->info('Almacenes...');
        $whs = [
            ['code' => 'ALM-CENTRAL', 'name' => 'Almacen Central', 'type' => 'MAIN'],
            ['code' => 'ALM-SUC',     'name' => 'Almacen Sucursal', 'type' => 'STORAGE'],
            ['code' => 'ALM-KIOSCO',  'name' => 'Kiosco Souvenirs',  'type' => 'DISPLAY'],
        ];
        $whIds = [];
        foreach ($whs as $w) {
            $db->getCollection('warehouses')->updateOne(
                ['code' => $w['code'], 'business_id' => $this->bid],
                ['$setOnInsert' => [
                    '_id' => new ObjectId(),
                    'business_id' => $this->bid,
                    'code' => $w['code'],
                    'name' => $w['name'],
                    'type' => $w['type'],
                    'active' => true,
                    'created_at' => new \MongoDB\BSON\UTCDateTime(now()),
                ]],
                ['upsert' => true]
            );
            $doc = $db->getCollection('warehouses')->findOne(['code' => $w['code'], 'business_id' => $this->bid]);
            $whIds[$w['code']] = (string) $doc['_id'];
        }

        // ------------------------------------------------------------
        // 3. Ubicaciones (4 por almacen)
        // ------------------------------------------------------------
        $this->info('Ubicaciones...');
        $locs = [
            ['warehouse' => 'ALM-CENTRAL', 'code' => 'A1-01', 'name' => 'Pasillo A1'],
            ['warehouse' => 'ALM-CENTRAL', 'code' => 'A1-02', 'name' => 'Pasillo A1 - Estante 2'],
            ['warehouse' => 'ALM-CENTRAL', 'code' => 'B2-01', 'name' => 'Pasillo B2'],
            ['warehouse' => 'ALM-SUC',     'code' => 'S-01',  'name' => 'Sucursal Principal'],
            ['warehouse' => 'ALM-SUC',     'code' => 'S-02',  'name' => 'Sucursal Bodega'],
            ['warehouse' => 'ALM-KIOSCO',  'code' => 'K-01',  'name' => 'Vitrina Kiosco'],
        ];
        $locIds = [];
        foreach ($locs as $l) {
            $db->getCollection('locations')->updateOne(
                ['code' => $l['code'], 'business_id' => $this->bid],
                ['$setOnInsert' => [
                    '_id' => new ObjectId(),
                    'business_id' => $this->bid,
                    'warehouse_id' => $whIds[$l['warehouse']],
                    'code' => $l['code'],
                    'name' => $l['name'],
                    'active' => true,
                    'created_at' => new \MongoDB\BSON\UTCDateTime(now()),
                ]],
                ['upsert' => true]
            );
            $doc = $db->getCollection('locations')->findOne(['code' => $l['code'], 'business_id' => $this->bid]);
            $locIds[$l['code']] = (string) $doc['_id'];
        }

        // ------------------------------------------------------------
        // 4. Productos
        // ------------------------------------------------------------
        $this->info('Productos...');
        $prods = [
            ['sku' => 'SOUV-TAZA-001',  'name' => 'Taza Campus Digital',      'cat' => 'Souvenirs',   'cost' => 35,  'price' => 89,   'min' => 20, 'max' => 100],
            ['sku' => 'SOUV-PLAY-001',  'name' => 'Playera Campus Negra',     'cat' => 'Souvenirs',   'cost' => 120, 'price' => 299,  'min' => 15, 'max' => 80],
            ['sku' => 'SOUV-GORR-001',  'name' => 'Gorra Campus Azul',        'cat' => 'Souvenirs',   'cost' => 90,  'price' => 199,  'min' => 10, 'max' => 60],
            ['sku' => 'SOUV-TERM-001',  'name' => 'Termo Acero 500ml',        'cat' => 'Souvenirs',   'cost' => 150, 'price' => 349,  'min' => 10, 'max' => 50],
            ['sku' => 'SOUV-CUAD-001',  'name' => 'Cuaderno Profesional',     'cat' => 'Souvenirs',   'cost' => 45,  'price' => 99,   'min' => 25, 'max' => 120],
            ['sku' => 'PAP-LAPIZ-001',  'name' => 'Lapiz HB x12',             'cat' => 'Papeleria',   'cost' => 25,  'price' => 55,   'min' => 50, 'max' => 300],
            ['sku' => 'PAP-FOLDER-001', 'name' => 'Folder Manila x25',        'cat' => 'Papeleria',   'cost' => 60,  'price' => 149,  'min' => 20, 'max' => 100],
            ['sku' => 'PAP-HOJAS-001',  'name' => 'Hojas Blancas 500',        'cat' => 'Papeleria',   'cost' => 55,  'price' => 129,  'min' => 30, 'max' => 150],
            ['sku' => 'ELE-CALC-001',   'name' => 'Calculadora Cientifica',   'cat' => 'Electronica', 'cost' => 220, 'price' => 499,  'min' => 5,  'max' => 30],
            ['sku' => 'ACC-MOCH-001',   'name' => 'Mochila Escolar',          'cat' => 'Accesorios',  'cost' => 180, 'price' => 399,  'min' => 10, 'max' => 40],
        ];
        $prodIds = [];
        foreach ($prods as $p) {
            $db->getCollection('products')->updateOne(
                ['sku' => $p['sku'], 'business_id' => $this->bid],
                ['$setOnInsert' => [
                    '_id' => new ObjectId(),
                    'business_id' => $this->bid,
                    'sku' => $p['sku'],
                    'name' => $p['name'],
                    'description' => 'Producto de demostracion',
                    'category' => Str::slug($p['cat']),
                    'category_id' => $catIds[$p['cat']] ?? null,
                    'cost' => $p['cost'],
                    'price' => $p['price'],
                    'stock_min' => $p['min'],
                    'stock_max' => $p['max'],
                    'active' => true,
                    'created_at' => new \MongoDB\BSON\UTCDateTime(now()),
                ]],
                ['upsert' => true]
            );
            $doc = $db->getCollection('products')->findOne(['sku' => $p['sku'], 'business_id' => $this->bid]);
            $prodIds[$p['sku']] = (string) $doc['_id'];
        }

        // ------------------------------------------------------------
        // 5. Inventario (stock distribuido)
        // ------------------------------------------------------------
        $this->info('Inventario...');
        // Distribucion: productos en varias ubicaciones
        $invPlan = [
            ['sku' => 'SOUV-TAZA-001',  'loc' => 'A1-01', 'qty' => 80],
            ['sku' => 'SOUV-TAZA-001',  'loc' => 'S-01',  'qty' => 30],
            ['sku' => 'SOUV-TAZA-001',  'loc' => 'K-01',  'qty' => 15],
            ['sku' => 'SOUV-PLAY-001',  'loc' => 'A1-01', 'qty' => 60],
            ['sku' => 'SOUV-PLAY-001',  'loc' => 'S-01',  'qty' => 25],
            ['sku' => 'SOUV-GORR-001',  'loc' => 'A1-02', 'qty' => 45],
            ['sku' => 'SOUV-GORR-001',  'loc' => 'K-01',  'qty' => 10],
            ['sku' => 'SOUV-TERM-001',  'loc' => 'A1-02', 'qty' => 35],
            ['sku' => 'SOUV-CUAD-001',  'loc' => 'A1-01', 'qty' => 100],
            ['sku' => 'SOUV-CUAD-001',  'loc' => 'K-01',  'qty' => 20],
            ['sku' => 'PAP-LAPIZ-001',  'loc' => 'B2-01', 'qty' => 250],
            ['sku' => 'PAP-FOLDER-001', 'loc' => 'B2-01', 'qty' => 90],
            ['sku' => 'PAP-HOJAS-001',  'loc' => 'B2-01', 'qty' => 130],
            ['sku' => 'ELE-CALC-001',   'loc' => 'A1-02', 'qty' => 20],
            ['sku' => 'ACC-MOCH-001',   'loc' => 'A1-01', 'qty' => 30],
            ['sku' => 'ACC-MOCH-001',   'loc' => 'S-02',  'qty' => 10],
        ];
        $invCreated = 0;
        foreach ($invPlan as $plan) {
            $pid = $prodIds[$plan['sku']] ?? null;
            $lid = $locIds[$plan['loc']] ?? null;
            if (!$pid || !$lid) continue;

            $exists = $db->getCollection('inventories')->findOne([
                'business_id' => $this->bid,
                'product_id' => $pid,
                'variant_id' => null,
                'location_id' => $lid,
            ]);
            if (!$exists || $this->option('force')) {
                $db->getCollection('inventories')->updateOne(
                    ['business_id' => $this->bid, 'product_id' => $pid, 'variant_id' => null, 'location_id' => $lid],
                    ['$set' => [
                        'on_hand' => $plan['qty'],
                        'reserved' => 0,
                        'available' => $plan['qty'],
                        'status' => 'AVAILABLE',
                        'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
                    ], '$setOnInsert' => [
                        '_id' => new ObjectId(),
                        'created_at' => new \MongoDB\BSON\UTCDateTime(now()),
                    ]],
                    ['upsert' => true]
                );
                $invCreated++;
            }
        }

        // ------------------------------------------------------------
        // 6. Proveedores
        // ------------------------------------------------------------
        $this->info('Proveedores...');
        $sups = [
            ['code' => 'PROV-PAP', 'legal' => 'Papeleria del Centro SA', 'trade' => 'Papeleria Centro', 'tax' => 'PAP950101ABC'],
            ['code' => 'PROV-SOU', 'legal' => 'Souvenirs MX SA',         'trade' => 'Souvenirs MX',     'tax' => 'SOU980505XYZ'],
            ['code' => 'PROV-ELE', 'legal' => 'Distribuidora Escolar',   'trade' => 'DistribEscolar',   'tax' => 'DIS020303DEF'],
        ];
        foreach ($sups as $s) {
            $db->getCollection('suppliers')->updateOne(
                ['code' => $s['code'], 'business_id' => $this->bid],
                ['$setOnInsert' => [
                    '_id' => new ObjectId(),
                    'business_id' => $this->bid,
                    'code' => $s['code'],
                    'legal_name' => $s['legal'],
                    'trade_name' => $s['trade'],
                    'tax_id' => $s['tax'],
                    'active' => true,
                    'created_at' => new \MongoDB\BSON\UTCDateTime(now()),
                ]],
                ['upsert' => true]
            );
        }

        // ------------------------------------------------------------
        // 7. Resumen
        // ------------------------------------------------------------
        $this->info('');
        $this->info('==================================================');
        $this->info('SEED COMPLETADO');
        $this->info('==================================================');
        $this->line('  Categorias:  ' . count($catIds));
        $this->line('  Almacenes:   ' . count($whIds));
        $this->line('  Ubicaciones: ' . count($locIds));
        $this->line('  Productos:   ' . count($prodIds));
        $this->line('  Inventario:  ' . $invCreated . ' registros nuevos');
        $this->line('  Proveedores: ' . count($sups));
        $this->line('');
        $this->line('  SKUs disponibles para tests E7:');
        foreach (array_keys($prodIds) as $sku) $this->line('    - ' . $sku);

        return self::SUCCESS;
    }
}