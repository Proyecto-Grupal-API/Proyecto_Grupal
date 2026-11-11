<?php

namespace App\Console\Commands;

use App\Domains\Financial\Enums\WalletStatus;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Services\WalletService;
use App\Models\Organizacion;
use App\Models\ServiceClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/** Local configuration only: no balance, payment, human role or financial authority is created. */
class PrepareCommunityEventPayments extends Command
{
    protected $signature = 'comunidad:preparar-pagos-eventos {organizacion : Slug de la organización receptora} {--activar : Configura el piloto local y habilita nuevas preparaciones para esta organización}';

    protected $description = 'Revisa o prepara pagos de eventos en la integración local, conservando receptores y credenciales existentes.';

    private const SCOPES = ['financial:event-payment:prepare', 'financial:event-payment:read', 'financial:event-payment:cancel'];

    public function handle(WalletService $wallets): int
    {
        if (! app()->environment('local')) {
            $this->error('Preparación limitada al entorno local; no concede autoridad productiva.');

            return self::FAILURE;
        }

        return Cache::store('file')->lock('comunidad:preparar-pagos-eventos', 30)->block(5, function () use ($wallets) {
            $org = Organizacion::where('slug', $this->argument('organizacion'))->where('estado', 'activa')->first();
            if (! $org) {
                $this->error('Se requiere una organización existente y activa.');

                return self::FAILURE;
            }
            $id = (string) $org->getKey();
            $wallet = $wallets->findByOwner('ORGANIZATION', $id, WalletType::ORGANIZACION);
            if ($wallet && ($wallet->status !== WalletStatus::ACTIVA || $wallet->currency !== 'MXN')) {
                $this->error('El receptor existente no es una wallet activa en MXN.');

                return self::FAILURE;
            }
            $this->line('Organización: '.$org->nombre.' ('.$id.')');
            $this->line($wallet ? 'Receptor: '.$wallet->public_id : 'Receptor pendiente: se creará con saldo cero al activar.');
            if (! $this->option('activar')) {
                $this->info('Revisión terminada; no se modificaron cuentas, credenciales ni configuración.');

                return self::SUCCESS;
            }
            $path = base_path('.env');
            if (! is_file($path) || app()->configurationIsCached()) {
                $this->error('Se requiere .env local sin configuración cacheada.');

                return self::FAILURE;
            }
            $content = file_get_contents($path);
            $client = config('comunidad.eventos_pagos.client_id');
            $financialClient = config('financial_event_payments.client_id');
            $secret = config('comunidad.eventos_pagos.client_secret');
            if ($client || $financialClient || $secret) {
                $record = is_string($client) ? ServiceClient::where('client_id', $client)->where('active', true)->first() : null;
                if ($client !== $financialClient || ! $record || ! is_string($secret)
                    || ! Hash::check($secret, $record->secret_hash)
                    || array_diff(self::SCOPES, is_array($record->scopes) ? $record->scopes : iterator_to_array($record->scopes)) !== []) {
                    $this->error('La conexión existente requiere revisión; no se sustituyeron clientes ni secretos.');

                    return self::FAILURE;
                }
            }
            $recipients = config('financial_event_payments.recipients', []);
            $allowed = config('comunidad.eventos_pagos.organizaciones');
            if (! is_array($recipients) || ($allowed !== null && (! is_array($allowed) || ! array_is_list($allowed)))) {
                $this->error('La configuración de receptores u organizaciones no es válida.');

                return self::FAILURE;
            }
            if (isset($recipients[$id]) && (! $wallet || ($recipients[$id]['wallet_id'] ?? null) !== strtolower($wallet->public_id))) {
                $this->error('Ya existe otro vínculo receptor; requiere revisión antes de cambiarlo.');

                return self::FAILURE;
            }
            $wallet ??= $wallets->create('ORGANIZATION', $id, WalletType::ORGANIZACION);
            if (! $client) {
                if (Artisan::call('oauth:client', ['name' => 'Comunidad: pagos de eventos locales', '--scope' => self::SCOPES]) !== 0) {
                    throw new RuntimeException('Identidad no pudo preparar el cliente de servicio.');
                }
                $output = Artisan::output();
                if (! preg_match('/client_id=(svc_[a-z0-9]+)/', $output, $ids)
                    || ! preg_match('/client_secret=([A-Za-z0-9]+)/', $output, $secrets)) {
                    throw new RuntimeException('Identidad no confirmó la credencial de servicio.');
                }
                $client = $ids[1];
                $secret = $secrets[1];
            }
            $recipients[$id] = ['wallet_id' => strtolower($wallet->public_id), 'name' => $org->nombre];
            $allowed = array_values(array_unique([...($allowed ?? array_keys($recipients)), $id]));
            $settings = [
                'COMUNIDAD_FINANZAS_URL' => 'http://app', 'COMUNIDAD_FINANZAS_CLIENT_ID' => $client,
                'COMUNIDAD_FINANZAS_CLIENT_SECRET' => $secret, 'FINANCIAL_EVENT_PAYMENTS_CLIENT_ID' => $client,
                'COMUNIDAD_EVENTOS_PAGOS_ORGANIZACIONES' => json_encode($allowed, JSON_THROW_ON_ERROR),
                'FINANCIAL_EVENT_RECIPIENTS_JSON' => json_encode($recipients, JSON_THROW_ON_ERROR | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE),
                'COMUNIDAD_EVENTOS_PAGOS_ENABLED' => 'true', 'FINANCIAL_EVENT_PAYMENTS_ENABLED' => 'true',
            ];
            foreach ($settings as $key => $value) {
                $line = $key."='".$value."'";
                $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
                $content = preg_match($pattern, $content)
                    ? preg_replace_callback($pattern, fn () => $line, $content) : rtrim($content)."\n".$line."\n";
            }
            $temp = tempnam(dirname($path), '.comunidad-env-');
            try {
                chmod($temp, 0600);
                if (file_put_contents($temp, $content, LOCK_EX) !== strlen($content) || ! rename($temp, $path)) {
                    throw new RuntimeException('No se pudo guardar la configuración local.');
                }
            } finally {
                if (is_file($temp)) { unlink($temp); }
            }
            $this->info('Piloto habilitado para la organización indicada. No se movieron fondos ni se asignaron roles.');
            $this->line('Receptor: '.$wallet->public_id.'. Credenciales conservadas exclusivamente en .env.');

            return self::SUCCESS;
        });
    }
}
