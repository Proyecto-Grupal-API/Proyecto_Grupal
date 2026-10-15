<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class PrepareServiceIntegration extends Command
{
    protected $signature = 'integracion:preparar-servicios';
    protected $description = 'Configura clientes OAuth locales mediante el comando de Identidad sin mostrar secretos.';
    public function handle(): int
    {
        if (! app()->environment('local')) { $this->error('Este comando prepara únicamente el entorno local alterno.'); return self::FAILURE; }
        $path = base_path('.env');
        $env = file_get_contents($path);
        $set = function (string $key, string $value) use (&$env) {
            $line = $key.'='.$value;
            $env = preg_match('/^'.preg_quote($key, '/').'=/m', $env)
                ? preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $env)
                : rtrim($env)."\n".$line."\n";
        };
        foreach ([
            ['SERVICIOS_IDENTIDAD', 'Integración E5 a E1', ['students:read', 'identity:qr:validate', 'identity:nfc:validate']],
            ['COMUNIDAD_SERVICIOS', 'Integración E6 a E5', ['services:benefits:read', 'services:benefits:write']],
            ['SERVICIOS_COMUNIDAD', 'Integración E5 a E6', ['notifications:send', 'notifications:read']],
        ] as [$prefix, $name, $scopes]) {
            if (! preg_match('/^'.$prefix.'_CLIENT_ID=svc_[a-z0-9]+$/m', $env)) {
                if (Artisan::call('oauth:client', ['name' => $name, '--scope' => $scopes]) !== 0) { return self::FAILURE; }
                $output = Artisan::output();
                if (! preg_match('/client_id=(svc_[a-z0-9]+)/', $output, $id) || ! preg_match('/client_secret=([A-Za-z0-9]+)/', $output, $secret)) {
                    $this->error('Identidad no confirmó la creación del cliente.'); return self::FAILURE;
                }
                $set($prefix.'_CLIENT_ID', $id[1]); $set($prefix.'_CLIENT_SECRET', $secret[1]);
                // Persist each created client immediately so a retry does not issue duplicate credentials.
                file_put_contents($path, $env, LOCK_EX);
            }
        }
        // Same Laravel app, genuine HTTP requests through Compose's app hostname.
        foreach (['SERVICIOS_IDENTIDAD_API_URL', 'SERVICIOS_API_URL', 'COMUNIDAD_API_URL', 'IDENTIDAD_API_URL'] as $key) { $set($key, 'http://app'); }
        if (! preg_match('/^IDENTIDAD_CLIENT_ID=.+$/m', $env)) {
            preg_match('/^SERVICIOS_IDENTIDAD_CLIENT_ID=(.+)$/m', $env, $id);
            preg_match('/^SERVICIOS_IDENTIDAD_CLIENT_SECRET=(.+)$/m', $env, $secret);
            $set('IDENTIDAD_CLIENT_ID', $id[1]); $set('IDENTIDAD_CLIENT_SECRET', $secret[1]);
        }
        file_put_contents($path, $env, LOCK_EX);
        $this->info('Clientes OAuth configurados. Los secretos quedaron únicamente en el .env local.');
        return self::SUCCESS;
    }
}
