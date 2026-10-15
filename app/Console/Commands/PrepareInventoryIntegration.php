<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use App\Models\InventoryModule\InventoryBusiness;

class PrepareInventoryIntegration extends Command
{
    protected $signature='integracion:preparar-inventario {--demo : Prepara datos E4 y asignación demo mediante un seeder de Identidad}';
    protected $description='Prepara clientes e índices de Inventarios en la integración local, sin imprimir secretos.';
    public function handle(): int
    {
        if (!app()->environment('local')) { $this->error('Preparación limitada al entorno local.'); return self::FAILURE; }
        $env=file_get_contents(base_path('.env'));
        $set=function ($key,$value) use (&$env) {
            $line=$key.'='.$value;
            $env=preg_match('/^'.preg_quote($key, '/').'=/m',$env) ? preg_replace('/^'.preg_quote($key, '/').'=.*$/m',$line,$env) : rtrim($env)."\n".$line."\n";
        };
        if (!preg_match('/^INVENTARIO_IDENTIDAD_CLIENT_ID=svc_[a-z0-9]+$/m',$env)) {
            if (Artisan::call('oauth:client',['name'=>'Integración E4 a E1', '--scope'=>['identity:assignments:read','identity:authorization:check']]) !== 0) { return self::FAILURE; }
            $out=Artisan::output();
            if (!preg_match('/client_id=(svc_[a-z0-9]+)/',$out,$id) || !preg_match('/client_secret=([A-Za-z0-9]+)/',$out,$secret)) { $this->error('No se confirmó el cliente de Identidad.');return self::FAILURE; }
            $set('INVENTARIO_IDENTIDAD_CLIENT_ID',$id[1]);$set('INVENTARIO_IDENTIDAD_CLIENT_SECRET',$secret[1]);
            file_put_contents(base_path('.env'),$env,LOCK_EX);
        }
        $set('INVENTARIO_IDENTIDAD_API_URL','http://app');
        // Consumer secrets differ: an E3 credential cannot acknowledge a payment or a points redemption.
        foreach (['INVENTARIO_EQ3_SECRETO','INVENTARIO_EQ2_SECRETO','INVENTARIO_EQ7_SECRETO'] as $key) {
            if (!preg_match('/^'.$key.'=.+$/m',$env)) { $set($key,Str::random(64)); }
        }
        file_put_contents(base_path('.env'),$env,LOCK_EX);
        $business=(string) config('team4.business_id');
        InventoryBusiness::firstOrCreate(['business_id'=>$business], ['name'=>'Souvenirs del Campus', 'active'=>true]);
        if (Artisan::call('migrate',['--path'=>'database/migrations/2026_10_14_000000_create_inventory_domain_indexes.php','--force'=>true]) !== 0) { $this->error('No se pudieron crear los índices de dominio.');return self::FAILURE; }
        if (Artisan::call('migrate', ['--path'=>'database/migrations/2026_10_14_000100_normalize_inventory_dates.php', '--force'=>true]) !== 0) { $this->error('No se pudieron normalizar las fechas de Inventarios.'); return self::FAILURE; }
        if (Artisan::call('team4:init-indexes') !== 0) { $this->error('No se pudieron preparar los índices de integración.');return self::FAILURE; }
        if ($this->option('demo')) {
            if (Artisan::call('inventario:demo') !== 0) { $this->error('No se pudo preparar el inventario demo.');return self::FAILURE; }
            if (Artisan::call('db:seed',['--class'=>\Database\Seeders\InventoryIdentityDemoSeeder::class,'--force'=>true]) !== 0) { $this->error('No se pudo preparar la asignación demo de Identidad.');return self::FAILURE; }
        }
        $this->info('Inventarios preparado. Credenciales únicamente en .env; cobros pendientes de Finanzas.');
        return self::SUCCESS;
    }
}
