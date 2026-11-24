<?php

// Adenda de la validación funcional (fase L): `campus:install --demo` deja una cuenta de demostración persistente e
// independiente por equipo (E1–E7) con sus complementarias, sólo como DATOS sintéticos (roles, membresías, cargos,
// delegaciones, negocios, monederos). Los seeders son idempotentes y nunca reescriben contraseña ni 2FA.
// Precondición (igual que CampusInstallCommandTest): la base SQL de pruebas ya tiene el esquema financiero.

use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Services\WalletService;
use App\Models\BusinessMembership;
use App\Models\DelegacionOrganizacion;
use App\Models\InventoryModule\InventoryBusiness;
use App\Models\Marketplace\Business;
use App\Models\Marketplace\Storefront;
use App\Models\MiembroOrganizacion;
use App\Models\Organizacion;
use App\Models\PermisoGestionOrganizaciones;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolOrganizacion;
use App\Models\ServiceClient;
use App\Models\StudentServices\Benefits\BenefitAssignment;
use App\Models\User;
use App\Services\PermisosComunidad;
use App\Services\StudentServices\Benefits\ServiceBenefitService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Demo\DemoTeams;
use Database\Seeders\GestionOrganizacionesSeeder;
use Database\Seeders\IntegracionEquipo1Seeder;
use Database\Seeders\StaffEventoSeeder;
use Database\Seeders\StudentServicesSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// La base SQL de pruebas no se reinicia entre pruebas: como las demás pruebas financieras, todo lo que se escribe en
// SQL Server (monederos y saldo inicial de la demo) va en una transacción que se revierte al terminar.
beforeEach(fn () => DB::connection('sqlsrv')->beginTransaction());
afterEach(function () {
    while (DB::connection('sqlsrv')->transactionLevel() > 0) {
        DB::connection('sqlsrv')->rollBack();
    }
});

function demoUser(string $alias): User
{
    return User::where('email', $alias.'@campus.test')->firstOrFail();
}

function demoBusinessRoles(User $user, string $business): array
{
    return RoleAssignment::where('user_id', (string) $user->getKey())->where('scope_type', 'business')
        ->where('scope_id', $business)->where('status', 'active')->pluck('role_key')->sort()->values()->all();
}

function demoInstall(array $options = []): void
{
    // La contraseña de demostración nunca está en el repositorio: aquí la fija la prueba (en una instalación la aporta el archivo privado).
    if (! isset($options['--env-file']) && ! config('demo.password')) {
        config(['demo.password' => 'solo-pruebas-'.bin2hex(random_bytes(8))]);
    }
    expect(Artisan::call('campus:install', ['--demo' => true] + $options))->toBe(0, Artisan::output());
}

test('campus:install --demo creates one persistent demo account per team with data-only roles', function () {
    demoInstall();

    foreach (DemoTeams::ACCOUNTS as $alias => $spec) {
        $user = demoUser($alias);
        expect($user->email_verified_at)->not->toBeNull()
            ->and((bool) $user->must_change_password)->toBeFalse()
            ->and((bool) $user->account_activation_pending)->toBeFalse()
            ->and($user->two_factor_confirmed_at)->toBeNull(); // el 2FA se enrola por interfaz; nunca se siembra
    }
    expect(array_keys(DemoTeams::ACCOUNTS))->toContain('demo.e1', 'demo.e2', 'demo.e3', 'demo.e4', 'demo.e5', 'demo.e6', 'demo.e7');

    // Administrador global sólo la cuenta de Identidad (E1), cuyo módulo es precisamente esa administración;
    // `maestro` para el profesor de E7. Ningún otro equipo usa el administrador global.
    $admins = collect(DemoTeams::ACCOUNTS)->keys()->filter(fn ($a) => demoUser($a)->hasRole(Role::ADMIN))->values()->all();
    expect($admins)->toBe(['demo.e1'])
        ->and(demoUser('demo.e7.profesor')->hasRole(Role::MAESTRO))->toBeTrue();

    // E5 y la revisión de E3: cargo propio del módulo por contexto de servicio.
    expect(demoUser('demo.e5')->hasRole(Role::ADMIN, 'service', 'servicios-estudiante'))->toBeTrue()
        ->and(demoUser('demo.e3.revisor')->hasRole(Role::ADMIN, 'service', 'tienda'))->toBeTrue()
        ->and(demoUser('demo.e5')->requiresTwoFactorAuthentication())->toBeTrue()
        ->and(demoUser('demo.e3.revisor')->requiresTwoFactorAuthentication())->toBeTrue();

    // E7: cargos por contexto de servicio (módulo 1.3 de Identidad), sin administrador global.
    expect(demoUser('demo.e7')->hasRole(Role::ADMIN, 'service', 'recompensas'))->toBeTrue()
        ->and(demoUser('demo.e7.plataforma')->hasRole(Role::ADMIN, 'service', 'plataforma'))->toBeTrue()
        ->and(demoUser('demo.e7.auditor')->hasRole(Role::ADMIN, 'service', 'auditoria'))->toBeTrue()
        ->and(demoUser('demo.e7')->requiresTwoFactorAuthentication())->toBeTrue(); // el contexto no rebaja la exigencia de 2FA

    // E4: consulta transversal de sólo lectura con el cargo exacto de Identidad, sin administrador global ni rol de negocio.
    expect(demoUser('demo.e4.consulta')->hasRole(Role::ADMIN, 'service', 'inventario-consulta'))->toBeTrue()
        ->and(demoUser('demo.e4.consulta')->requiresTwoFactorAuthentication())->toBeTrue();

    // E6: coordinación de comunicaciones con el cargo exacto de Identidad; la presidencia no lo tiene.
    expect(demoUser('demo.e6.comunicaciones')->hasRole(Role::ADMIN, 'service', 'comunicaciones'))->toBeTrue()
        ->and(\App\Support\Authorization\CoordinadorComunicaciones::permite(demoUser('demo.e6')))->toBeFalse();

    // E1: alumno con perfil académico.
    expect(demoUser('demo.e1.alumno')->studentProfile)->not->toBeNull();

    // E2: organización con id fijo, presidencia, delegaciones de caja vigentes y monederos personales.
    $org = Organizacion::findOrFail(DemoTeams::E2_ORGANIZATION_ID);
    config(['financial.cash.community_associations' => [DemoTeams::E2_ORGANIZATION_ID]]);
    $caps = app(PermisosComunidad::class);
    expect($caps->permiteAccionCaja((string) demoUser('demo.e2')->getKey(), (string) $org->id, 'operate'))->toBeTrue()
        ->and($caps->permiteAccionCaja((string) demoUser('demo.e2')->getKey(), (string) $org->id, 'manage'))->toBeTrue()
        ->and($caps->permiteAccionCaja((string) demoUser('demo.e2.supervisor')->getKey(), (string) $org->id, 'approval_review'))->toBeTrue()
        ->and($caps->permiteAccionCaja((string) demoUser('demo.e2.supervisor')->getKey(), (string) $org->id, 'operate'))->toBeFalse()
        ->and($caps->permiteAccionCaja((string) demoUser('demo.e2.presidencia')->getKey(), (string) $org->id, 'operate'))->toBeFalse()
        ->and($caps->permiteAccionCaja((string) demoUser('demo.e2.alumno')->getKey(), (string) $org->id, 'read'))->toBeFalse();
    foreach (['demo.e2.alumno', 'demo.e2.alumno2', 'demo.e3.comprador'] as $alias) {
        expect(app(WalletService::class)->findByOwner('USER', (string) demoUser($alias)->getKey(), WalletType::USUARIO))->not->toBeNull();
    }

    // E3: negocio aprobado con tienda publicada, dueño y cajero por asignación de E1, monederos y saldo del comprador.
    $business = Business::findOrFail(DemoTeams::E3_BUSINESS_ID);
    expect($business->status)->toBe('approved')
        ->and(Storefront::where('business_id', DemoTeams::E3_BUSINESS_ID)->value('published'))->toBeTrue()
        ->and(demoBusinessRoles(demoUser('demo.e3'), DemoTeams::E3_BUSINESS_ID))->toBe(['business_owner'])
        ->and(demoBusinessRoles(demoUser('demo.e3.cajero'), DemoTeams::E3_BUSINESS_ID))->toBe(['cashier'])
        ->and(app(WalletService::class)->findByOwner('BUSINESS', DemoTeams::E3_BUSINESS_ID, WalletType::NEGOCIO))->not->toBeNull()
        ->and(app(WalletService::class)->findByOwner('USER', (string) demoUser('demo.e3.comprador')->getKey(), WalletType::USUARIO)->available_balance_cents)
        ->toBe(DemoTeams::E3_BUYER_OPENING_CENTS);

    // E4: negocio de inventario por defecto con tres papeles separados y un alumno sin rol.
    $inventory = (string) config('team4.business_id');
    expect(InventoryBusiness::where('business_id', $inventory)->where('active', true)->exists())->toBeTrue()
        ->and(demoBusinessRoles(demoUser('demo.e4'), $inventory))->toBe(['business_owner'])
        ->and(demoBusinessRoles(demoUser('demo.e4.gerente'), $inventory))->toBe(['business_manager'])
        ->and(demoBusinessRoles(demoUser('demo.e4.almacen'), $inventory))->toBe(['inventory_manager'])
        ->and(BusinessMembership::where('user_id', (string) demoUser('demo.e4.alumno')->getKey())->exists())->toBeFalse();

    // E5: el alumno tiene monedero con saldo sintético y un apoyo de impresiones vigente; existe la cuenta institucional de Servicios.
    $e5 = demoUser('demo.e5.alumno');
    $e5Wallet = app(WalletService::class)->findByOwner('USER', strtolower((string) $e5->getKey()), WalletType::USUARIO);
    expect($e5Wallet)->not->toBeNull()->and((int) $e5Wallet->available_balance_cents)->toBe(DemoTeams::E5_STUDENT_OPENING_CENTS)
        ->and(app(ServiceBenefitService::class)->printBalanceFor((string) $e5->getKey())['disponible'] ?? 0)->toBe(DemoTeams::E5_PRINT_ALLOWANCE_PAGES)
        ->and(BenefitAssignment::where('student_id', (string) $e5->getKey())->count())->toBe(1);
    // E2: cargos de servicio para los controles financieros (administración y consulta), sin administrador global; el operador de caja
    // tiene monedero propio, para demostrar que la caja no le deja recargarse a sí mismo.
    expect(demoUser('demo.e2.controles')->hasRole(Role::ADMIN, 'service', 'finanzas'))->toBeTrue()
        ->and(demoUser('demo.e2.consulta')->hasRole(Role::ADMIN, 'service', 'finanzas-consulta'))->toBeTrue()
        ->and(demoUser('demo.e2.controles')->hasRole(Role::ADMIN))->toBeFalse()
        ->and(app(WalletService::class)->findByOwner('USER', strtolower((string) demoUser('demo.e2')->getKey()), WalletType::USUARIO))->not->toBeNull();
    // E6: la asociación tiene cuenta receptora y el integrante que compra boletos tiene monedero con saldo sintético.
    $e6 = demoUser('demo.e6.integrante');
    expect(app(WalletService::class)->findByOwner('ORGANIZATION', DemoTeams::E6_ORGANIZATION_ID, WalletType::ORGANIZACION))->not->toBeNull()
        ->and((int) app(WalletService::class)->findByOwner('USER', strtolower((string) $e6->getKey()), WalletType::USUARIO)->available_balance_cents)->toBe(DemoTeams::E6_MEMBER_OPENING_CENTS);
    // E5: alumnos con perfil activo.
    expect(demoUser('demo.e5.alumno')->studentProfile?->academic_status?->value ?? demoUser('demo.e5.alumno')->studentProfile?->academic_status)->toBe('active');

    // E6: organización propia con presidencia, integrantes y gestor de organizaciones.
    $e6 = Organizacion::findOrFail(DemoTeams::E6_ORGANIZATION_ID);
    expect(RolOrganizacion::where('organizacion_id', (string) $e6->id)->where('slug_rol', 'presidencia')->value('usuario_id'))
        ->toBe((string) demoUser('demo.e6')->getKey())
        ->and(MiembroOrganizacion::where('organizacion_id', (string) $e6->id)->where('estado', 'activo')->count())->toBe(4)
        ->and(PermisoGestionOrganizaciones::where('usuario_id', (string) demoUser('demo.e6.gestor')->getKey())->where('activo', true)->exists())->toBeTrue();
});

test('a second run is idempotent and never rewrites passwords or two factor secrets', function () {
    demoInstall();
    $admin = demoUser('demo.e1');
    $admin->forceFill(['password' => 'clave-cambiada-por-el-usuario', 'two_factor_secret' => 'secreto-ya-enrolado', 'two_factor_confirmed_at' => now()])->save();
    $before = [
        'users' => User::withTrashed()->count(),
        'hashes' => User::orderBy('email')->pluck('password', 'email')->all(),
        'assignments' => RoleAssignment::count(),
        'memberships' => BusinessMembership::count(),
        'members' => MiembroOrganizacion::count(),
        'delegations' => DelegacionOrganizacion::count(),
        'organizations' => Organizacion::count(),
        'buyer' => app(WalletService::class)->findByOwner('USER', (string) demoUser('demo.e3.comprador')->getKey(), WalletType::USUARIO)->available_balance_cents,
    ];

    demoInstall();

    $after = [
        'users' => User::withTrashed()->count(),
        'hashes' => User::orderBy('email')->pluck('password', 'email')->all(),
        'assignments' => RoleAssignment::count(),
        'memberships' => BusinessMembership::count(),
        'members' => MiembroOrganizacion::count(),
        'delegations' => DelegacionOrganizacion::count(),
        'organizations' => Organizacion::count(),
        'buyer' => app(WalletService::class)->findByOwner('USER', (string) demoUser('demo.e3.comprador')->getKey(), WalletType::USUARIO)->available_balance_cents,
    ];
    expect($after)->toBe($before);
    $admin->refresh();
    expect($admin->two_factor_secret)->toBe('secreto-ya-enrolado')->and($admin->two_factor_confirmed_at)->not->toBeNull();
});

test('a demo account seeded earlier as global administrator is corrected to its service charge', function () {
    demoInstall();
    $program = demoUser('demo.e7');
    $program->assignRole(Role::ADMIN); // así la dejaba la versión anterior del sembrador
    expect($program->fresh()->hasRole(Role::ADMIN))->toBeTrue();
    foreach (['demo.e5', 'demo.e3.revisor'] as $alias) {
        demoUser($alias)->assignRole(Role::ADMIN);
    }

    demoInstall();

    expect(demoUser('demo.e5')->hasRole(Role::ADMIN))->toBeFalse()
        ->and(demoUser('demo.e5')->hasRole(Role::ADMIN, 'service', 'servicios-estudiante'))->toBeTrue()
        ->and(demoUser('demo.e3.revisor')->hasRole(Role::ADMIN))->toBeFalse()
        ->and(demoUser('demo.e3.revisor')->hasRole(Role::ADMIN, 'service', 'tienda'))->toBeTrue();

    $program = demoUser('demo.e7');
    expect($program->hasRole(Role::ADMIN))->toBeFalse()
        ->and($program->hasRole(Role::ADMIN, 'service', 'recompensas'))->toBeTrue()
        ->and(RoleAssignment::where('user_id', (string) $program->getKey())->where('role_key', Role::ADMIN)->whereNull('scope_type')->where('status', 'revoked')->count())->toBe(1);
});

test('a closed demo account is not recreated nor reopened', function () {
    demoInstall();
    demoUser('demo.e1.alumno')->delete();
    $total = User::withTrashed()->count();

    demoInstall();

    expect(User::withTrashed()->count())->toBe($total)
        ->and(User::where('email', 'demo.e1.alumno@campus.test')->exists())->toBeFalse();
});

test('the lab configuration file gets the integration settings once and is never overwritten', function () {
    $path = sys_get_temp_dir().'/campus-demo-'.bin2hex(random_bytes(6)).'.env';
    try {
        demoInstall(['--env-file' => $path, '--env-format' => 'container', '--internal-url' => 'http://app:8080']);
        $first = file_get_contents($path);
        expect($first)->toContain('INVENTARIO_IDENTIDAD_CLIENT_ID=svc_', 'MARKETPLACE_IDENTIDAD_CLIENT_ID=svc_', 'SERVICIOS_IDENTIDAD_CLIENT_ID=svc_',
            'INVENTARIO_IDENTIDAD_API_URL=http://app:8080', 'FINANCIAL_COMMUNITY_CASH_ASSOCIATIONS='.DemoTeams::E2_ORGANIZATION_ID,
            'FINANCIAL_PERSONAL_WALLET_TRANSFERS_ENABLED=true', 'MARKETPLACE_WALLET_CHECKOUT_ENABLED=true', 'FINANCIAL_MARKETPLACE_ENABLED=true',
            // eventos y auditoría hacia Recompensas (E7)
            'PLATFORM_INGEST_ENABLED=true', 'EVENTS_TRANSPORT=platform_hmac', 'EVENTS_SINK_URL=http://127.0.0.1:8080/api/v1/plataforma/eventos',
            'TEAM7_WEBHOOK_URL=http://127.0.0.1:8080/api/v1/integrations/ingest-event', 'MARKETPLACE_REWARDS_ENABLED=true', 'MARKETPLACE_REWARDS_URL=http://127.0.0.1:8080',
            'PLATFORM_DELIVERY_ENABLED=true', 'PLATFORM_SERVICES_DELIVERY_ENABLED=true', 'PLATFORM_COMMUNITY_ANALYTICS_SINCE=20')
            ->and(substr(sprintf('%o', fileperms($path)), -3))->toBe('600');
        // El emisor y el receptor de Identidad y de Inventarios comparten el mismo secreto; cada pareja tiene el suyo.
        $value = fn (string $key) => substr((string) collect(explode("\n", $first))->first(fn ($l) => str_starts_with($l, $key.'=')), strlen($key) + 1);
        expect($value('PLATFORM_IDENTITY_SECRET'))->toBe($value('EVENTS_PLATFORM_SECRET'))->and(strlen($value('PLATFORM_IDENTITY_SECRET')))->toBe(64)
            ->and($value('PLATFORM_INVENTORY_SECRET'))->toBe($value('INVENTARIO_E7_SECRETO'))
            ->and($value('PLATFORM_MARKETPLACE_SECRET'))->not->toBe($value('PLATFORM_COMMUNITY_SECRET'));
        // Cobros de Servicios (E5) por Finanzas: un cliente propio, las dos banderas de cada lado y la cuenta institucional.
        $wallet = app(WalletService::class)->findByOwner('ORGANIZATION', DemoTeams::E5_SERVICES_ORGANIZATION, WalletType::ORGANIZACION);
        expect($first)->toContain('SERVICIOS_FINANZAS_MULTAS_CLIENT_ID=svc_', 'SERVICIOS_FINANZAS_API_URL=http://app:8080', 'SERVICIOS_FINANZAS_MULTAS_ENABLED=true',
            'SERVICIOS_FINANZAS_SERVICIOS_ENABLED=true', 'FINANCIAL_LIBRARY_FINES_ENABLED=true', 'FINANCIAL_STUDENT_SERVICES_ENABLED=true',
            'FINANCIAL_LIBRARY_FINES_RECIPIENT_ORGANIZATION_ID='.DemoTeams::E5_SERVICES_ORGANIZATION)
            ->and($wallet)->not->toBeNull()
            ->and($value('FINANCIAL_LIBRARY_FINES_RECIPIENT_WALLET_ID'))->toBe(strtolower((string) $wallet->public_id))
            ->and($value('FINANCIAL_LIBRARY_FINES_CLIENT_ID'))->toBe($value('SERVICIOS_FINANZAS_MULTAS_CLIENT_ID'))
            ->and($value('FINANCIAL_STUDENT_SERVICES_CLIENT_ID'))->toBe($value('SERVICIOS_FINANZAS_MULTAS_CLIENT_ID'));
        $scopes = ServiceClient::where('client_id', $value('SERVICIOS_FINANZAS_MULTAS_CLIENT_ID'))->first()->scopes;
        expect(collect($scopes)->sort()->values()->all())->toBe(['financial:library-fine:cancel', 'financial:library-fine:prepare', 'financial:library-fine:read',
            'financial:student-service:cancel', 'financial:student-service:prepare', 'financial:student-service:read']);
        // Cobro de eventos de Comunidad (E6) por Finanzas: lo mismo que deja la orden oficial `comunidad:preparar-pagos-eventos`.
        $orgWallet = app(WalletService::class)->findByOwner('ORGANIZATION', DemoTeams::E6_ORGANIZATION_ID, WalletType::ORGANIZACION);
        $json = fn (string $key) => json_decode(substr((string) collect(explode("\n", $first))->first(fn ($l) => str_starts_with($l, $key.'=')), strlen($key) + 1), true);
        expect($first)->toContain('COMUNIDAD_FINANZAS_CLIENT_ID=svc_', 'COMUNIDAD_FINANZAS_URL=http://app:8080', 'COMUNIDAD_EVENTOS_PAGOS_ENABLED=true', 'FINANCIAL_EVENT_PAYMENTS_ENABLED=true')
            ->and($orgWallet)->not->toBeNull()
            ->and($value('FINANCIAL_EVENT_PAYMENTS_CLIENT_ID'))->toBe($value('COMUNIDAD_FINANZAS_CLIENT_ID'))
            ->and($json('COMUNIDAD_EVENTOS_PAGOS_ORGANIZACIONES'))->toBe([DemoTeams::E6_ORGANIZATION_ID])
            ->and($json('FINANCIAL_EVENT_RECIPIENTS_JSON'))->toBe([DemoTeams::E6_ORGANIZATION_ID => ['wallet_id' => strtolower((string) $orgWallet->public_id), 'name' => 'Asociación Demo E6']]);
        expect(collect(ServiceClient::where('client_id', $value('COMUNIDAD_FINANZAS_CLIENT_ID'))->first()->scopes)->sort()->values()->all())
            ->toBe(['financial:event-payment:cancel', 'financial:event-payment:prepare', 'financial:event-payment:read']);
        // Finanzas (E2): los controles web se abren a los cargos de servicio declarados y los avisos a Comunidad quedan encendidos.
        expect($first)->toContain('FINANCIAL_CONTROL_MANAGE_ROLES=admin@service:finanzas', 'FINANCIAL_CONTROL_VIEW_ROLES=admin@service:finanzas-consulta',
            'FINANCIAL_COMMUNITY_NOTICES_ENABLED=true', 'FINANCIAL_COMMUNITY_NOTICES_URL=http://app:8080', 'FINANCIAL_COMMUNITY_NOTICES_CLIENT_ID=svc_', 'FINANCIAL_COMMUNITY_NOTICES_SINCE=20');
        expect(collect(ServiceClient::where('client_id', $value('FINANCIAL_COMMUNITY_NOTICES_CLIENT_ID'))->first()->scopes)->sort()->values()->all())->toBe(['notifications:read', 'notifications:send']);
        $line = (string) collect(explode("\n", $first))->first(fn ($l) => str_starts_with($l, 'FINANCIAL_MARKETPLACE_CLIENTS_JSON='));
        $clients = json_decode(substr($line, strlen('FINANCIAL_MARKETPLACE_CLIENTS_JSON=')), true);
        expect($clients)->toBeArray()->and(collect($clients)->first()['businesses'] ?? [])->toHaveKey(DemoTeams::E3_BUSINESS_ID);

        demoInstall(['--env-file' => $path, '--env-format' => 'container', '--internal-url' => 'http://app:8080']);

        expect(file_get_contents($path))->toBe($first);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

// P-14 y conciliación de caja: dos integraciones que el producto trae apagadas y que la demostración enciende sólo en su
// archivo privado de laboratorio. La configuración por defecto del producto no cambia.
test('the lab configuration turns on the payment notice from Finanzas to Inventarios and the cash reconciliation, and nothing else changes by default', function () {
    $path = sys_get_temp_dir().'/campus-demo-'.bin2hex(random_bytes(6)).'.env';
    try {
        demoInstall(['--env-file' => $path, '--env-format' => 'container', '--internal-url' => 'http://app:8080']);
        $first = file_get_contents($path);
        $value = fn (string $key) => substr((string) collect(explode("\n", $first))->first(fn ($l) => str_starts_with($l, $key.'=')), strlen($key) + 1);

        // Emisor (Finanzas) y receptor (Inventarios) encendidos, hacia la propia instalación y con la misma firma.
        expect($first)->toContain('FINANCIAL_TEAM4_WEBHOOK_ENABLED=true', 'INVENTARIO_PAGOS_HABILITADOS=true',
            'FINANCIAL_TEAM4_WEBHOOK_URL=http://127.0.0.1:8080/api/equipo4/webhooks/payment-status')
            ->and(strlen($value('INVENTARIO_EQ2_SECRETO')))->toBe(64)
            ->and($value('FINANCIAL_TEAM4_WEBHOOK_SECRET'))->toBe($value('INVENTARIO_EQ2_SECRETO'))
            // Sólo se entregan los pagos de la tienda de demostración.
            ->and($value('FINANCIAL_TEAM4_PILOT_CLIENT_ID'))->toBe($value('MARKETPLACE_FINANZAS_CLIENT_ID'))
            ->and($value('FINANCIAL_TEAM4_PILOT_CLIENT_ID'))->toStartWith('svc_');
        // El receptor de Inventarios acepta pagos de un solo negocio: en la demostración, el de la tienda que cobra con monedero.
        expect($first)->toContain('TEAM4_API_BUSINESS_ID='.DemoTeams::E3_BUSINESS_ID);
        // FIN-03: la integridad de matrículas se atesta porque el preflight de sólo lectura aprobó los datos sembrados.
        expect($first)->toContain('IDENTITY_ENROLLMENT_INTEGRITY_VERIFIED=true')
            ->and(app(\App\Services\Directory\EnrollmentIntegrityPreflight::class)->run()['passed'])->toBeTrue();

        // Conciliación de caja de Finanzas.
        expect($first)->toContain('FINANCIAL_CASH_RECONCILIATION_ENABLED=true');

        // Una segunda pasada no reescribe nada.
        demoInstall(['--env-file' => $path, '--env-format' => 'container', '--internal-url' => 'http://app:8080']);
        expect(file_get_contents($path))->toBe($first);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }

    // El producto las trae apagadas: sin esas claves en el entorno, nada se enciende.
    $defaults = fn (string $file) => (function () use ($file) {
        return require base_path('config/'.$file.'.php');
    })();
    $previous = [];
    foreach (['FINANCIAL_TEAM4_WEBHOOK_ENABLED', 'INVENTARIO_PAGOS_HABILITADOS', 'FINANCIAL_CASH_RECONCILIATION_ENABLED'] as $key) {
        $previous[$key] = getenv($key);
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
    try {
        expect($defaults('financial_team4')['enabled'])->toBeFalse()
            ->and((bool) $defaults('team4')['integration']['payment_events_enabled'])->toBeFalse()
            ->and((bool) $defaults('financial')['cash']['reconciliation_enabled'])->toBeFalse();
    } finally {
        foreach ($previous as $key => $old) {
            if ($old !== false) {
                putenv($key.'='.$old);
            }
        }
    }
});

// ---------- Adenda 21.E: la contraseña de demostración sólo vive en un archivo privado, fuera del repositorio ----------

test('the demo password is not written anywhere in the demo seeders', function () {
    foreach (glob(database_path('seeders/Demo/*.php')) as $file) {
        $source = file_get_contents($file);
        expect($source)->not->toMatch('/const\\s+PASSWORD\\b/')
            ->and($source)->not->toMatch("/env\\('CAMPUS_DEMO_PASSWORD',\\s*[^)]/");   // sin valor de reserva
    }
});

test('without a password source the demo install refuses and creates no demo account', function () {
    config(['demo.password' => null]);
    $this->artisan('campus:install', ['--demo' => true])->expectsOutputToContain('CAMPUS_DEMO_PASSWORD')->assertExitCode(1);
    expect(User::where('email', 'like', 'demo.%@campus.test')->count())->toBe(0);
});

test('the installer generates the demo password into the private file once and the accounts use it', function () {
    config(['demo.password' => null]);
    $path = sys_get_temp_dir().'/campus-demo-'.bin2hex(random_bytes(6)).'.env';
    try {
        demoInstall(['--env-file' => $path, '--env-format' => 'container', '--internal-url' => 'http://app:8080']);
        $line = (string) collect(explode("\n", file_get_contents($path)))->first(fn ($l) => str_starts_with($l, 'CAMPUS_DEMO_PASSWORD='));
        $password = substr($line, strlen('CAMPUS_DEMO_PASSWORD='));
        expect(strlen($password))->toBeGreaterThanOrEqual(24)
            ->and(substr(sprintf('%o', fileperms($path)), -3))->toBe('600')
            ->and(Hash::check($password, demoUser('demo.e1')->password))->toBeTrue()
            ->and(Hash::check($password, demoUser('demo.e7.alumno')->password))->toBeTrue()
            ->and(Artisan::output())->not->toContain($password);

        // Una segunda pasada conserva la misma contraseña (lee el archivo privado, no la regenera).
        config(['demo.password' => null]);
        $this->artisan('campus:install', ['--demo' => true, '--env-file' => $path, '--env-format' => 'container', '--internal-url' => 'http://app:8080'])
            ->doesntExpectOutputToContain($password)->assertSuccessful();
        expect(file_get_contents($path))->toContain('CAMPUS_DEMO_PASSWORD='.$password)
            ->and(substr_count(file_get_contents($path), 'CAMPUS_DEMO_PASSWORD='))->toBe(1);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

// ---------- Adenda 21.E: las cuentas de la demostración anterior (sembradores ya publicados) ----------
// Cuatro sembradores que `campus:install --demo` ejecuta, y uno suelto, creaban sus cuentas con una contraseña escrita
// en el código publicado; una de ellas (`identidad@campus.test`) es administradora global de Identidad. Su contraseña
// pasa a salir, como la de las cuentas por equipo, sólo de la configuración privada de la instalación.

const EARLIER_DEMO_ACCOUNTS = ['presidencia@campus.test', 'estudiante@campus.test', 'consejo@campus.test', 'andrea@campus.test',
    'staff@campus.test', 'gestion@campus.test', 'identidad@campus.test'];

test('no seeder writes an account password in the code', function () {
    $files = collect(iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(database_path('seeders'), FilesystemIterator::SKIP_DOTS))))
        ->filter(fn (SplFileInfo $file) => $file->getExtension() === 'php');
    expect($files->count())->toBeGreaterThan(20);
    $withLiteral = $files->filter(fn (SplFileInfo $file) => preg_match(
        "/['\"]password['\"]\\s*=>\\s*(?:bcrypt\\(|Hash::make\\()?\\s*['\"]/", file_get_contents($file->getPathname())) === 1)
        ->map(fn (SplFileInfo $file) => $file->getFilename())->sort()->values()->all();
    expect($withLiteral)->toBe([]);
});

test('the installer gives the earlier demonstration accounts the password of the private file', function () {
    config(['demo.password' => null]);
    $path = sys_get_temp_dir().'/campus-demo-'.bin2hex(random_bytes(6)).'.env';
    try {
        demoInstall(['--env-file' => $path, '--env-format' => 'container', '--internal-url' => 'http://app:8080']);
        $line = (string) collect(explode("\n", file_get_contents($path)))->first(fn ($l) => str_starts_with($l, 'CAMPUS_DEMO_PASSWORD='));
        $password = substr($line, strlen('CAMPUS_DEMO_PASSWORD='));
        $other = User::whereIn('email', EARLIER_DEMO_ACCOUNTS)->get()->reject(fn (User $user) => Hash::check($password, $user->password))->pluck('email')->sort()->values()->all();
        expect(User::whereIn('email', EARLIER_DEMO_ACCOUNTS)->count())->toBe(count(EARLIER_DEMO_ACCOUNTS))
            ->and($other)->toBe([])
            ->and(demoUser('identidad')->hasRole(Role::ADMIN))->toBeTrue();   // sigue siendo la administración que Identidad publicó
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

test('without a password source the earlier demonstration seeders refuse before creating anything', function () {
    config(['demo.password' => null]);
    foreach ([DatabaseSeeder::class, IntegracionEquipo1Seeder::class, StaffEventoSeeder::class, GestionOrganizacionesSeeder::class, StudentServicesSeeder::class] as $seeder) {
        expect(fn () => $this->seed($seeder))->toThrow(RuntimeException::class, 'CAMPUS_DEMO_PASSWORD');
    }
    expect(User::count())->toBe(0);
});

test('running an earlier demonstration seeder again never rewrites the password of an existing account', function () {
    config(['demo.password' => $first = 'primera-'.bin2hex(random_bytes(8))]);
    $this->seed(DatabaseSeeder::class);
    config(['demo.password' => 'segunda-'.bin2hex(random_bytes(8))]);
    $this->seed(DatabaseSeeder::class);
    foreach (['presidencia@campus.test', 'estudiante@campus.test', 'consejo@campus.test', 'andrea@campus.test'] as $email) {
        expect(Hash::check($first, User::where('email', $email)->firstOrFail()->password))->toBeTrue($email);
    }
});

test('the seeder of example students only runs in local or testing', function () {
    app()->detectEnvironment(fn () => 'production');
    try {
        expect(fn () => (new StudentServicesSeeder)->run())->toThrow(RuntimeException::class, 'local');
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
    expect(User::count())->toBe(0);
});
