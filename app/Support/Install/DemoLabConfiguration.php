<?php

namespace App\Support\Install;

use App\Services\Directory\EnrollmentIntegrityPreflight;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Services\WalletService;
use App\Models\ServiceClient;
use Database\Seeders\Demo\DemoTeams;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Configuración de LABORATORIO que necesita la demostración por equipo y que no es un dato de la base: clientes OAuth
 * entre módulos, URL internas, firmas compartidas y banderas de piloto. La escribe `campus:install --demo` en el archivo
 * de entorno indicado. Regla: lo ya inicializado no se toca; sólo se repone un cliente cuyo registro no existe en esta base.
 */
final class DemoLabConfiguration
{
    /** prefijo de las variables => [nombre del cliente, scopes] (los mismos de las órdenes `integracion:preparar-*`). */
    public const CLIENTS = [
        'INVENTARIO_IDENTIDAD' => ['Demo · E4 a E1', ['identity:assignments:read', 'identity:authorization:check']],
        'MARKETPLACE_IDENTIDAD' => ['Demo · E3 a E1', ['identity:assignments:read', 'identity:authorization:check', 'identity:business-owner:provision']],
        'MARKETPLACE_COMUNIDAD' => ['Demo · E3 a E6', ['community:organizations:read']],
        'MARKETPLACE_FINANZAS' => ['Demo · E3 a E2', ['financial:purchase:prepare', 'financial:purchase:read', 'financial:purchase:cancel']],
        'SERVICIOS_IDENTIDAD' => ['Demo · E5 a E1', ['students:read', 'identity:qr:validate', 'identity:nfc:validate']],
        'SERVICIOS_COMUNIDAD' => ['Demo · E5 a E6', ['notifications:send', 'notifications:read']],
        'SERVICIOS_FINANZAS_MULTAS' => ['Demo · E5 a E2', ['financial:library-fine:prepare', 'financial:library-fine:read', 'financial:library-fine:cancel',
            'financial:student-service:prepare', 'financial:student-service:read', 'financial:student-service:cancel']],
        'COMUNIDAD_SERVICIOS' => ['Demo · E6 a E5', ['services:benefits:read', 'services:benefits:write']],
        'IDENTIDAD' => ['Demo · E6 a E1', ['students:read']],
        'FINANCIAL_COMMUNITY_NOTICES' => ['Demo · E2 a E6', ['notifications:send', 'notifications:read']],
        'COMUNIDAD_FINANZAS' => ['Demo · E6 a E2', ['financial:event-payment:prepare', 'financial:event-payment:read', 'financial:event-payment:cancel']],
    ];

    public const INTERNAL_URLS = ['INVENTARIO_IDENTIDAD_API_URL', 'MARKETPLACE_IDENTIDAD_URL', 'MARKETPLACE_COMUNIDAD_URL',
        'MARKETPLACE_INVENTARIO_URL', 'MARKETPLACE_FINANZAS_URL', 'SERVICIOS_IDENTIDAD_API_URL', 'SERVICIOS_API_URL', 'SERVICIOS_FINANZAS_API_URL', 'COMUNIDAD_FINANZAS_URL', 'FINANCIAL_COMMUNITY_NOTICES_URL',
        'COMUNIDAD_API_URL', 'IDENTIDAD_API_URL'];

    public const SHARED_SECRETS = ['INVENTARIO_EQ3_SECRETO', 'INVENTARIO_EQ2_SECRETO', 'INVENTARIO_EQ7_SECRETO'];

    public const FLAGS = ['FINANCIAL_PERSONAL_WALLET_TRANSFERS_ENABLED', 'MARKETPLACE_WALLET_CHECKOUT_ENABLED', 'FINANCIAL_MARKETPLACE_ENABLED',
        // Cobros de Servicios al Estudiante (multas, lockers e impresiones) por Finanzas: bandera del emisor y del receptor.
        'SERVICIOS_FINANZAS_MULTAS_ENABLED', 'SERVICIOS_FINANZAS_SERVICIOS_ENABLED', 'FINANCIAL_LIBRARY_FINES_ENABLED', 'FINANCIAL_STUDENT_SERVICES_ENABLED',
        // Cobro de eventos de Comunidad con monedero (lo mismo que deja `comunidad:preparar-pagos-eventos --activar`).
        'COMUNIDAD_EVENTOS_PAGOS_ENABLED', 'FINANCIAL_EVENT_PAYMENTS_ENABLED',
        // Avisos de Finanzas a la bandeja de Comunidad.
        'FINANCIAL_COMMUNITY_NOTICES_ENABLED',
        // Aviso de pagos de Finanzas a Inventarios (emisor y receptor): sin él, un pedido de Tienda con inventario pagado con
        // monedero queda cobrado y sin confirmar. El producto lo trae apagado; se enciende sólo en este archivo de laboratorio.
        'FINANCIAL_TEAM4_WEBHOOK_ENABLED', 'INVENTARIO_PAGOS_HABILITADOS',
        // Conciliación de caja: la conciliación diaria compara también los arqueos de los turnos.
        'FINANCIAL_CASH_RECONCILIATION_ENABLED'];

    /** Ruta del receptor de pagos de Inventarios; Finanzas sólo acepta esta ruta, y por http sólo en local o pruebas. */
    public const TEAM4_PAYMENT_WEBHOOK_PATH = '/api/equipo4/webhooks/payment-status';

    /** Cargos de Identidad que abren los controles financieros web en la demostración (la política la declara cada instalación). */
    public const FINANCIAL_CONTROL_ROLES = ['FINANCIAL_CONTROL_MANAGE_ROLES' => 'admin@service:finanzas', 'FINANCIAL_CONTROL_VIEW_ROLES' => 'admin@service:finanzas-consulta'];

    /*
     * Eventos y auditoría hacia Recompensas y plataforma (E7): «todos los equipos publican eventos y auditoría para
     * dashboards». Cada emisor firma con un secreto que el receptor conoce; aquí se generan y se encienden las banderas.
     */
    /** receptor => emisor (null cuando emisor y receptor leen la misma clave). */
    public const PLATFORM_SECRETS = ['PLATFORM_IDENTITY_SECRET' => 'EVENTS_PLATFORM_SECRET', 'PLATFORM_INVENTORY_SECRET' => 'INVENTARIO_E7_SECRETO',
        'PLATFORM_SERVICES_SECRET' => null, 'PLATFORM_COMMUNITY_SECRET' => null, 'PLATFORM_MARKETPLACE_SECRET' => null];

    public const PLATFORM_FLAGS = ['PLATFORM_INGEST_ENABLED' => 'true', 'EVENTS_TRANSPORT' => 'platform_hmac', 'INVENTARIO_E7_OUTBOX_HABILITADO' => 'true',
        'PLATFORM_SERVICES_DELIVERY_ENABLED' => 'true', 'PLATFORM_SERVICES_ANALYTICS_ENABLED' => 'true', 'PLATFORM_DELIVERY_ENABLED' => 'true',
        'PLATFORM_COMMUNITY_ANALYTICS_ENABLED' => 'true', 'MARKETPLACE_REWARDS_ENABLED' => 'true'];

    /** clave => ruta que se añade a la URL de bucle local (los emisores sólo aceptan http hacia el propio equipo). */
    public const PLATFORM_URLS = ['EVENTS_SINK_URL' => '/api/v1/plataforma/eventos', 'TEAM7_WEBHOOK_URL' => '/api/v1/integrations/ingest-event',
        'PLATFORM_SERVICES_API_URL' => '', 'PLATFORM_API_URL' => '', 'MARKETPLACE_REWARDS_URL' => ''];

    /** Sólo se publica lo ocurrido desde que se preparó el laboratorio. */
    public const PLATFORM_SINCE = ['PLATFORM_COMMUNITY_ANALYTICS_SINCE', 'PLATFORM_SERVICES_DELIVERY_SINCE'];

    /** @return list<string> claves escritas en esta pasada (nunca valores) */
    public function apply(LabEnvFile $env, string $internalUrl, string $loopbackUrl = 'http://127.0.0.1:8080'): array
    {
        $written = [];
        foreach (self::PLATFORM_SECRETS as $receiver => $sender) {
            if ($env->get($receiver) === null) {
                $secret = $sender !== null && $env->get($sender) !== null ? (string) $env->get($sender) : Str::random(64);
                $env->put($receiver, $secret);
                $written[] = $receiver;
            }
            if ($sender !== null && $env->get($sender) === null) {
                $env->put($sender, (string) $env->get($receiver));
                $written[] = $sender;
            }
        }
        foreach (self::PLATFORM_FLAGS as $key => $value) {
            $env->putIfMissing($key, $value) && $written[] = $key;
        }
        foreach (self::PLATFORM_URLS as $key => $path) {
            $env->putIfMissing($key, rtrim($loopbackUrl, '/').$path) && $written[] = $key;
        }
        foreach (self::FINANCIAL_CONTROL_ROLES as $key => $value) {
            $env->putIfMissing($key, $value) && $written[] = $key;
        }
        // Sólo se avisa de lo ocurrido desde que se preparó el laboratorio (instante ISO 8601 con zona).
        $env->putIfMissing('FINANCIAL_COMMUNITY_NOTICES_SINCE', now()->utc()->format('Y-m-d\\TH:i:sP')) && $written[] = 'FINANCIAL_COMMUNITY_NOTICES_SINCE';
        foreach (self::PLATFORM_SINCE as $key) {
            $env->putIfMissing($key, now()->utc()->format('Y-m-d\\TH:i:s\\Z')) && $written[] = $key;
        }
        foreach (self::CLIENTS as $prefix => [$name, $scopes]) {
            $id = $env->get($prefix.'_CLIENT_ID');
            if ($id !== null && $env->get($prefix.'_CLIENT_SECRET') !== null && ServiceClient::where('client_id', $id)->where('active', true)->exists()) {
                continue;
            }
            $secret = Str::random(64);
            $client = ServiceClient::create(['name' => $name, 'client_id' => 'svc_'.Str::lower(Str::random(24)),
                'secret_hash' => Hash::make($secret), 'scopes' => $scopes, 'active' => true]);
            $env->put($prefix.'_CLIENT_ID', $client->client_id);
            $env->put($prefix.'_CLIENT_SECRET', $secret);
            array_push($written, $prefix.'_CLIENT_ID', $prefix.'_CLIENT_SECRET');
        }
        foreach (self::INTERNAL_URLS as $key) {
            $env->putIfMissing($key, rtrim($internalUrl, '/')) && $written[] = $key;
        }
        foreach (self::SHARED_SECRETS as $key) {
            $env->putIfMissing($key, Str::random(64)) && $written[] = $key;
        }
        foreach (self::FLAGS as $key) {
            $env->putIfMissing($key, 'true') && $written[] = $key;
        }
        // Finanzas firma el aviso de pago con el secreto que Inventarios ya espera de ese emisor, y lo envía a la propia instalación.
        // Sólo se entregan los pagos hechos con el cliente de la tienda de demostración.
        $env->putIfMissing('FINANCIAL_TEAM4_WEBHOOK_SECRET', (string) $env->get('INVENTARIO_EQ2_SECRETO')) && $written[] = 'FINANCIAL_TEAM4_WEBHOOK_SECRET';
        $env->putIfMissing('FINANCIAL_TEAM4_WEBHOOK_URL', rtrim($loopbackUrl, '/').self::TEAM4_PAYMENT_WEBHOOK_PATH) && $written[] = 'FINANCIAL_TEAM4_WEBHOOK_URL';
        // El receptor de pagos de Inventarios acepta un solo negocio (no va en la ruta firmada): en la demostración es la tienda
        // que cobra con monedero. El inventario de demostración de E4 sigue en su propio negocio.
        $env->putIfMissing('TEAM4_API_BUSINESS_ID', DemoTeams::E3_BUSINESS_ID) && $written[] = 'TEAM4_API_BUSINESS_ID';
        if ($env->get('MARKETPLACE_FINANZAS_CLIENT_ID') !== null) {
            $env->putIfMissing('FINANCIAL_TEAM4_PILOT_CLIENT_ID', (string) $env->get('MARKETPLACE_FINANZAS_CLIENT_ID')) && $written[] = 'FINANCIAL_TEAM4_PILOT_CLIENT_ID';
        }
        // El cliente de Tienda es el único autorizado a dar de alta propietarios de negocio en Identidad.
        $owner = (string) $env->get('MARKETPLACE_IDENTIDAD_CLIENT_ID');
        if (! in_array($owner, $this->csv($env->get('OAUTH2_BUSINESS_OWNER_CLIENTS')), true)) {
            $env->put('OAUTH2_BUSINESS_OWNER_CLIENTS', $owner);
            $written[] = 'OAUTH2_BUSINESS_OWNER_CLIENTS';
        }
        // La caja sólo opera en las organizaciones listadas: se añade la de la demostración de E2 sin quitar otras.
        $cash = $this->csv($env->get('FINANCIAL_COMMUNITY_CASH_ASSOCIATIONS'));
        if (! in_array(DemoTeams::E2_ORGANIZATION_ID, $cash, true)) {
            $env->put('FINANCIAL_COMMUNITY_CASH_ASSOCIATIONS', implode(',', [...$cash, DemoTeams::E2_ORGANIZATION_ID]));
            $written[] = 'FINANCIAL_COMMUNITY_CASH_ASSOCIATIONS';
        }
        // Cobro con monedero de la tienda de demostración: E2 sólo acepta clientes y negocios declarados aquí.
        $wallet = app(WalletService::class)->findByOwner('BUSINESS', DemoTeams::E3_BUSINESS_ID, WalletType::NEGOCIO);
        $finance = (string) $env->get('MARKETPLACE_FINANZAS_CLIENT_ID');
        $declared = json_decode((string) $env->get('FINANCIAL_MARKETPLACE_CLIENTS_JSON'), true);
        if ($wallet && ! isset($declared[$finance]['recipient_wallets'][DemoTeams::E3_BUSINESS_ID])) {
            $env->putJson('FINANCIAL_MARKETPLACE_CLIENTS_JSON', [$finance => ['name' => 'Marketplace de demostración',
                'businesses' => [DemoTeams::E3_BUSINESS_ID => 'Tienda Demo E3'],
                'recipient_wallets' => [DemoTeams::E3_BUSINESS_ID => strtolower((string) $wallet->public_id)]]], true);
            $written[] = 'FINANCIAL_MARKETPLACE_CLIENTS_JSON';
        }
        // Cobros de Servicios al Estudiante: Finanzas sólo acepta al cliente declarado y deposita en la cuenta institucional.
        $services = app(WalletService::class)->findByOwner('ORGANIZATION', DemoTeams::E5_SERVICES_ORGANIZATION, WalletType::ORGANIZACION);
        $servicesClient = (string) $env->get('SERVICIOS_FINANZAS_MULTAS_CLIENT_ID');
        foreach (['FINANCIAL_LIBRARY_FINES_CLIENT_ID', 'FINANCIAL_STUDENT_SERVICES_CLIENT_ID'] as $key) {
            if ($env->get($key) !== $servicesClient) {
                $env->put($key, $servicesClient);
                $written[] = $key;
            }
        }
        if ($services) {
            $env->putIfMissing('FINANCIAL_LIBRARY_FINES_RECIPIENT_ORGANIZATION_ID', DemoTeams::E5_SERVICES_ORGANIZATION) && $written[] = 'FINANCIAL_LIBRARY_FINES_RECIPIENT_ORGANIZATION_ID';
            $env->putIfMissing('FINANCIAL_LIBRARY_FINES_RECIPIENT_WALLET_ID', strtolower((string) $services->public_id)) && $written[] = 'FINANCIAL_LIBRARY_FINES_RECIPIENT_WALLET_ID';
        }
        // Cobro de eventos de Comunidad: Finanzas sólo acepta al cliente declarado y a las organizaciones con receptor aprobado.
        $eventsClient = (string) $env->get('COMUNIDAD_FINANZAS_CLIENT_ID');
        if ($env->get('FINANCIAL_EVENT_PAYMENTS_CLIENT_ID') !== $eventsClient) {
            $env->put('FINANCIAL_EVENT_PAYMENTS_CLIENT_ID', $eventsClient);
            $written[] = 'FINANCIAL_EVENT_PAYMENTS_CLIENT_ID';
        }
        $association = app(WalletService::class)->findByOwner('ORGANIZATION', DemoTeams::E6_ORGANIZATION_ID, WalletType::ORGANIZACION);
        if ($association) {
            $recipients = json_decode((string) $env->get('FINANCIAL_EVENT_RECIPIENTS_JSON'), true);
            if (! isset($recipients[DemoTeams::E6_ORGANIZATION_ID])) {
                $env->putJson('FINANCIAL_EVENT_RECIPIENTS_JSON', [...(is_array($recipients) ? $recipients : []), DemoTeams::E6_ORGANIZATION_ID => [
                    'wallet_id' => strtolower((string) $association->public_id), 'name' => DemoTeams::E6_ORGANIZATION_NAME]], true);
                $written[] = 'FINANCIAL_EVENT_RECIPIENTS_JSON';
            }
            $allowed = json_decode((string) $env->get('COMUNIDAD_EVENTOS_PAGOS_ORGANIZACIONES'), true);
            if (! is_array($allowed) || ! in_array(DemoTeams::E6_ORGANIZATION_ID, $allowed, true)) {
                $env->putJson('COMUNIDAD_EVENTOS_PAGOS_ORGANIZACIONES', array_values(array_unique([...(is_array($allowed) ? $allowed : []), DemoTeams::E6_ORGANIZATION_ID])), true);
                $written[] = 'COMUNIDAD_EVENTOS_PAGOS_ORGANIZACIONES';
            }
        }
        // FIN-03: the directory resolves enrollments only after E1 attests their integrity (contract D1). In the demo the
        // attestation is the read-only preflight over the freshly seeded synthetic data; if it fails, nothing is written.
        if ($env->get('IDENTITY_ENROLLMENT_INTEGRITY_VERIFIED') === null && app(EnrollmentIntegrityPreflight::class)->run()['passed']) {
            $env->put('IDENTITY_ENROLLMENT_INTEGRITY_VERIFIED', 'true');
            $written[] = 'IDENTITY_ENROLLMENT_INTEGRITY_VERIFIED';
        }
        $env->save();

        return $written;
    }

    /** @return list<string> */
    private function csv(?string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn ($v) => $v !== ''));
    }
}
