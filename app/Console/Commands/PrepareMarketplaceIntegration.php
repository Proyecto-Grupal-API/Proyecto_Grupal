<?php

namespace App\Console\Commands;

use App\Models\Marketplace\Business;
use App\Models\Marketplace\Product;
use App\Models\Marketplace\Storefront;
use App\Models\User;
use App\Services\Marketplace\MarketplaceIntegration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class PrepareMarketplaceIntegration extends Command
{
    protected $signature = 'integracion:preparar-marketplace {--demo : Vincula el catálogo demo existente de Inventarios}';

    protected $description = 'Prepara APIs de Marketplace en el alterno local; requiere aprobación explícita de los clientes E1.';

    public function handle(MarketplaceIntegration $integration): int
    {
        if (! app()->environment('local')) {
            $this->error('Preparación limitada al entorno local.');

            return self::FAILURE;
        }
        $env = file_get_contents(base_path('.env'));
        $set = function ($key, $value) use (&$env) {
            $line = $key.'='.$value;
            $env = preg_match('/^'.preg_quote($key, '/').'=/m', $env) ? preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $env) : rtrim($env)."\n".$line."\n";
        };
        $get = function ($key) use (&$env) {
            preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $env, $m);

            return trim($m[1] ?? '', "\"' \r");
        };
        foreach (['IDENTIDAD' => ['identity:assignments:read', 'identity:authorization:check', 'identity:business-owner:provision'],
            'COMUNIDAD' => ['community:organizations:read']] as $name => $scopes) {
            $prefix = 'MARKETPLACE_'.$name;
            if (! $get($prefix.'_CLIENT_ID')) {
                if (Artisan::call('oauth:client', ['name' => 'Marketplace a '.$name, '--scope' => $scopes]) !== 0) {
                    $this->error('No se pudo preparar el cliente asociado.');

                    return self::FAILURE;
                }
                $output = Artisan::output();
                if (! preg_match('/client_id=(svc_[a-z0-9]+)/', $output, $id) || ! preg_match('/client_secret=([A-Za-z0-9]+)/', $output, $secret)) {
                    return self::FAILURE;
                }
                $set($prefix.'_CLIENT_ID', $id[1]);
                $set($prefix.'_CLIENT_SECRET', $secret[1]);
            }
            $set($prefix.'_URL', 'http://app');
            config(['marketplace.'.($name === 'IDENTIDAD' ? 'identidad' : 'comunidad') => ['url' => 'http://app', 'client_id' => $get($prefix.'_CLIENT_ID'), 'client_secret' => $get($prefix.'_CLIENT_SECRET')]]);
        }
        $clients = array_values(array_unique(array_filter(array_merge(config('oauth.business_owner_clients', []), [$get('MARKETPLACE_IDENTIDAD_CLIENT_ID')]))));
        $set('OAUTH2_BUSINESS_OWNER_CLIENTS', implode(',', $clients));
        config(['oauth.business_owner_clients' => $clients]);
        file_put_contents(base_path('.env'), $env, LOCK_EX);
        Artisan::call('config:clear');
        if (Artisan::call('migrate', ['--path' => 'database/migrations/2026_10_14_000200_create_marketplace_indexes.php', '--force' => true]) !== 0) {
            $this->error('No se confirmaron los índices.');

            return self::FAILURE;
        }
        if ($this->option('demo')) {
            // Explicit local fixture lookup, never a runtime authorization fallback. E1 must confirm the assignment.
            $subject = User::where('email', 'identidad@campus.test')->firstOrFail();
            $id = (string) config('team4.business_id');
            if (! $integration->allowed((string) $subject->getKey(), $id, 'business.manage', true)) {
                $this->error('La asignación demo no está vigente en E1. No se modificaron permisos.');

                return self::FAILURE;
            }
            $catalog = $integration->inventoryCatalog($id);
            $business = Business::firstOrCreate(['_id' => $id], ['name' => 'Souvenirs del Campus', 'slug' => 'souvenirs-del-campus', 'type' => 'souvenirs', 'owner_id' => (string) $subject->getKey(),
                'description' => 'Artículos del campus vinculados al inventario de E4.', 'status' => 'approved', 'visibility' => 'public', 'verified' => true, 'inventory_ready' => true, 'payment_methods' => ['wallet', 'spei'], 'tags' => ['Campus']]);
            Storefront::firstOrCreate(['business_id' => $id], ['name' => $business->name, 'brand' => 'Campus Digital', 'description' => $business->description, 'published' => true, 'policies' => [], 'delivery_points' => ['Biblioteca Central']]);
            $count = 0;
            foreach ($catalog['products'] as $physical) {
                $physicalId = (string) ($physical['id'] ?? $physical['_id'] ?? '');
                $stock = collect($catalog['inventory'])->first(fn ($row) => (string) ($row['product_id'] ?? '') === $physicalId && empty($row['variant_id']));
                if (! $stock || ! $physicalId) {
                    continue;
                }
                Product::firstOrCreate(['business_id' => $id, 'slug' => 'inventario-'.$physicalId], ['name' => $physical['name'], 'kind' => 'product', 'category' => 'Souvenirs', 'description' => 'Producto del catálogo de Inventarios.',
                    'price' => number_format(50 + $count * 15, 2, '.', ''), 'currency' => 'MXN', 'active' => true, 'available' => true, 'inventory_required' => true, 'inventory_product_id' => $physicalId, 'inventory_location_id' => (string) $stock['location_id'], 'image' => null]);
                $count++;
            }
            $this->info('Artículos demo vinculados: '.$count.'. Precios de prueba; existencias reales de E4.');
        }
        $this->info('Marketplace preparado. Credenciales solo en .env. Pagos, puntos y cambios de personal pendientes de sus APIs.');

        return self::SUCCESS;
    }
}
