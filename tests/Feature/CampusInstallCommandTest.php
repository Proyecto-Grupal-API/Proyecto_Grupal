<?php

// DoD §21 del diseño: «migraciones y seeders funcionan desde una base limpia». `campus:install` orquesta las tres rutas de
// migración (Mongo raíz, Mongo plataforma E7, SQL Server finanzas E2), los catálogos base idempotentes y los índices de
// idempotencia de E4. Antes de esta orden, `php artisan migrate` sólo aplicaba la carpeta raíz.
//
// Precondición del arnés integrado: Laravel cambia la conexión por defecto mientras ejecuta cada migración de otra
// conexión (Migrator::runMethod) y el guardia de pruebas lo prohíbe a propósito. Por eso la base SQL Server de pruebas
// se prepara con `campus:install` FUERA del arnés antes de la suite; aquí la ruta financiera debe quedar sin pendientes.
// La aplicación de las 59 migraciones financieras desde cero se verifica en las instalaciones limpias reales.

use App\Actions\Students\UpsertStudentProfile;
use App\Models\AcademicProgram;
use App\Models\Campus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

// `--demo` siembra monederos sintéticos en SQL Server, que no se reinicia entre pruebas: se revierte al terminar.
beforeEach(fn () => DB::connection('sqlsrv')->beginTransaction());
afterEach(function () {
    while (DB::connection('sqlsrv')->transactionLevel() > 0) {
        DB::connection('sqlsrv')->rollBack();
    }
});

function installPendingCount(?string $connection, string $path): int
{
    $migrator = app('migrator');
    $repository = $migrator->getRepository();
    try {
        $repository->setSource($connection);
        $ran = $repository->repositoryExists() ? $repository->getRan() : [];
    } finally {
        $repository->setSource(null);
    }
    $files = array_keys($migrator->getMigrationFiles(base_path($path)));

    return count(array_diff($files, $ran));
}

function installMongoCollections(): array
{
    return iterator_to_array(DB::connection('mongodb')->getDatabase()->listCollectionNames());
}

test('campus:install --check reports a clean database as not installed and writes nothing', function () {
    $before = installMongoCollections();

    $this->artisan('campus:install', ['--check' => true])
        ->expectsOutputToContain('Pendientes')
        ->assertFailed();

    expect(installMongoCollections())->toBe($before)
        ->and(installPendingCount(null, 'database/migrations'))->toBeGreaterThan(0);
});

test('campus:install applies all three migration paths, base catalogs and indexes, and is idempotent', function () {
    $this->artisan('campus:install')->assertSuccessful();

    expect(installPendingCount(null, 'database/migrations'))->toBe(0)
        ->and(installPendingCount(null, 'database/migrations/platform'))->toBe(0)
        ->and(installPendingCount('sqlsrv', 'database/migrations/financial'))->toBe(0)
        ->and(Schema::connection('sqlsrv')->hasTable('wallets'))->toBeTrue()
        ->and(Schema::connection('sqlsrv')->hasTable('ledger_entries'))->toBeTrue()
        ->and(in_array('points_accounts', installMongoCollections(), true))->toBeTrue()
        ->and(Role::where('name', Role::ADMIN)->exists())->toBeTrue()
        ->and(Permission::count())->toBeGreaterThan(0)
        ->and(Campus::where('code', 'CENTRAL')->exists())->toBeTrue();
    $indexes = collect(DB::connection('inventory')->getCollection('integration_idempotency')->listIndexes())->map(fn ($i) => $i->getName());
    expect($indexes->contains('uniq_service_idem'))->toBeTrue();

    $roles = Role::count();
    $permissions = Permission::count();
    $this->artisan('campus:install')->assertSuccessful();
    expect(Role::count())->toBe($roles)->and(Permission::count())->toBe($permissions);

    $this->artisan('campus:install', ['--check' => true])->expectsOutputToContain('Instalación completa')->assertSuccessful();
});

test('campus:install --demo seeds the synthetic demo accounts in testing', function () {
    config(['demo.password' => 'solo-pruebas-'.bin2hex(random_bytes(8))]);   // en una instalación la aporta el archivo privado
    $this->artisan('campus:install', ['--demo' => true])->assertSuccessful();

    expect(User::where('email', 'presidencia@campus.test')->exists())->toBeTrue()
        ->and(User::where('email', 'identidad@campus.test')->first()?->hasRole(Role::ADMIN))->toBeTrue();
});

// Defecto E1 «no se pueden crear usuarios» (reproducido en una instalación hecha sólo con `php artisan migrate`):
// sin catálogo de roles, el alta de alumno y la asignación de administrador fallan con «El rol debe existir en el
// catálogo.» porque el alta asigna el rol `estudiante`. `campus:install` siembra el catálogo y el alta funciona.
test('E1 user creation fails without the role catalog and works after campus:install', function () {
    $student = fn (string $suffix) => [
        'name' => 'Alumno sintético '.$suffix, 'email' => "alumno-$suffix@example.invalid", 'enrollment_number' => 'VF-'.strtoupper($suffix),
        'campus_id' => (string) Campus::where('code', 'CENTRAL')->value('_id'),
        'academic_program_id' => (string) AcademicProgram::where('code', 'ISC')->value('_id'),
        'current_semester' => 1, 'academic_status' => 'active',
    ];
    $admin = User::factory()->create();

    expect(fn () => $admin->assignRole(Role::ADMIN))->toThrow(ValidationException::class, 'El rol debe existir en el catálogo.');

    $this->artisan('campus:install')->assertSuccessful();

    $admin->assignRole(Role::ADMIN);
    $created = app(UpsertStudentProfile::class)->execute($student('a1'), null, $admin);
    expect($admin->fresh()->hasRole(Role::ADMIN))->toBeTrue()
        ->and($created->fresh()->hasRole(Role::ESTUDIANTE))->toBeTrue()
        ->and($created->studentProfile)->not->toBeNull();
});
