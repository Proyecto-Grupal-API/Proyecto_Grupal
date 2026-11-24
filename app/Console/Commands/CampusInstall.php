<?php

namespace App\Console\Commands;

use App\Support\Install\DemoLabConfiguration;
use App\Support\Install\LabEnvFile;
use Database\Seeders\AuthorizationFoundationSeeder;
use Database\Seeders\Demo\DemoTeamsSeeder;
use Database\Seeders\IntegracionEquipo1Seeder;
use Database\Seeders\InventoryIdentityDemoSeeder;
use Database\Seeders\ServiciosIntegracionSeeder;
use Database\Seeders\StudentCatalogSeeder;
use Database\Seeders\TipoBeneficioSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Instalación reproducible desde una base limpia (Definition of Done §21 del diseño).
 *
 * `php artisan migrate` sólo aplica `database/migrations`: ningún ServiceProvider registra las rutas
 * `database/migrations/platform` (E7, MongoDB) ni `database/migrations/financial` (E2, SQL Server), y los catálogos
 * de roles y permisos no se cargan solos. Esta orden aplica las tres rutas, siembra los catálogos base idempotentes y
 * crea los índices de idempotencia de E4. Requisitos previos (infraestructura): MongoDB con réplica y SQL Server con
 * la base de `SQLSRV_DATABASE` creada; fuera de `testing` el usuario de MongoDB necesita las bases `<base>`,
 * `<base>_inventarios`, `<base>_marketplace` y `<base>_plataforma`.
 */
class CampusInstall extends Command
{
    protected $signature = 'campus:install
        {--check : Sólo verifica el estado de la instalación; no escribe}
        {--demo : Además carga datos sintéticos de demostración (sólo local o testing)}
        {--env-file= : Con --demo: archivo de entorno donde dejar la configuración de laboratorio (clientes OAuth, URL internas, pilotos)}
        {--env-format=dotenv : Formato de ese archivo: dotenv o container (--env-file de contenedores)}
                {--internal-url=http://app : Con --demo: URL con la que los módulos se llaman entre sí dentro del despliegue}
        {--loopback-url=http://127.0.0.1:8080 : Con --demo: URL de bucle local de este mismo servidor, para los emisores de eventos hacia Recompensas}
        {--force : Necesario para instalar en producción}';

    protected $description = 'Instala o completa el esquema (MongoDB + SQL Server), catálogos base e índices de Campus Digital.';

    /** @var array<int, array{label: string, database: ?string, path: string}> */
    public const MIGRATION_PATHS = [
        ['label' => 'MongoDB · núcleo (E1, E3–E6)', 'database' => null, 'path' => 'database/migrations'],
        ['label' => 'MongoDB · plataforma (E7)', 'database' => null, 'path' => 'database/migrations/platform'],
        ['label' => 'SQL Server · finanzas (E2)', 'database' => 'sqlsrv', 'path' => 'database/migrations/financial'],
    ];

    public const BASE_SEEDERS = [AuthorizationFoundationSeeder::class, TipoBeneficioSeeder::class, StudentCatalogSeeder::class];

    /** Los tres primeros son la demo integrada previa; el último, las cuentas independientes por equipo (E1–E7). */
    public const DEMO_SEEDERS = [IntegracionEquipo1Seeder::class, ServiciosIntegracionSeeder::class, InventoryIdentityDemoSeeder::class,
        DemoTeamsSeeder::class];

    public function handle(): int
    {
        $this->targets();
        if (! $this->reachable()) {
            return self::FAILURE;
        }

        if ($this->option('check')) {
            return $this->report() ? self::SUCCESS : self::FAILURE;
        }
        $refusal = self::refusal(app()->environment(), (bool) $this->option('demo'), (bool) $this->option('force'));
        if ($refusal !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        foreach (self::MIGRATION_PATHS as $step) {
            // Only paths with pending migrations are migrated: `migrate --database=X` switches the default connection
            // even when there is nothing to run, so skipping complete paths avoids needless global state changes.
            $pending = $this->pending($step['database'], $step['path']);
            $this->line($step['label'].': '.($pending === 0 ? 'sin pendientes' : "aplicando $pending migraciones…"));
            if ($pending > 0 && ! $this->migrate($step['database'], $step['path'])) {
                return self::FAILURE;
            }
        }
        foreach (self::BASE_SEEDERS as $seeder) {
            if (! $this->call_('db:seed', ['--class' => $seeder, '--force' => true])) {
                return self::FAILURE;
            }
        }
        if (! $this->call_('team4:init-indexes')) {
            return self::FAILURE;
        }
        if ($this->option('demo')) {
            if (! $this->demoPassword()) {
                return self::FAILURE;
            }
            foreach (self::DEMO_SEEDERS as $seeder) {
                if (! $this->call_('db:seed', ['--class' => $seeder, '--force' => true])) {
                    return self::FAILURE;
                }
            }
            $this->labConfiguration();
        }

        return $this->report() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Contraseña de las cuentas de demostración (adenda 21.E): sólo en configuración privada, nunca en el repositorio ni en
     * la salida. Si el entorno no la trae, se lee del archivo privado indicado o se genera allí una sola vez.
     */
    private function demoPassword(): bool
    {
        $current = config('demo.password');
        if (is_string($current) && strlen($current) >= 12) {
            return true;
        }
        $path = (string) $this->option('env-file');
        if ($path === '') {
            $this->error('Falta la contraseña de demostración. Define CAMPUS_DEMO_PASSWORD en un archivo privado (12 caracteres o más) '
                .'o indica --env-file=<archivo privado> para que el instalador la genere allí. No se sembró ninguna cuenta de demostración.');

            return false;
        }
        $env = new LabEnvFile($path, $this->option('env-format') !== 'container');
        $password = $env->get('CAMPUS_DEMO_PASSWORD');
        if (! is_string($password) || strlen($password) < 12) {
            $password = Str::random(32);
            $env->put('CAMPUS_DEMO_PASSWORD', $password);
            $env->save();
            $this->line("Contraseña de demostración: generada en {$path} (clave CAMPUS_DEMO_PASSWORD; el valor no se muestra).");
        }
        config(['demo.password' => $password]);

        return true;
    }

    /** Configuración de laboratorio de la demo: sólo si se indica el archivo; nunca imprime valores. */
    private function labConfiguration(): void
    {
        $path = (string) $this->option('env-file');
        if ($path === '') {
            $this->line('Configuración de laboratorio: omitida (indica --env-file=<archivo> para dejar clientes OAuth, URL internas y pilotos de la demo).');

            return;
        }
        $written = app(DemoLabConfiguration::class)->apply(
            new LabEnvFile($path, $this->option('env-format') !== 'container'), (string) $this->option('internal-url'), (string) $this->option('loopback-url'));
        $this->line($written === []
            ? "Configuración de laboratorio: sin cambios en {$path}."
            : 'Configuración de laboratorio: '.count($written)." claves nuevas en {$path} (".implode(', ', $written).'). Reinicia el servidor para cargarlas.');
    }

    /** Regla previa a cualquier escritura: demo sólo en local/testing; producción exige --force. */
    public static function refusal(string $environment, bool $demo, bool $force): ?string
    {
        if ($demo && ! in_array($environment, ['local', 'testing'], true)) {
            return 'Los datos de demostración se cargan sólo en local o testing. No se escribió nada.';
        }
        if ($environment === 'production' && ! $force) {
            return 'Entorno de producción: repite con --force para confirmar la instalación. No se escribió nada.';
        }

        return null;
    }

    private function targets(): void
    {
        $mongo = (string) config('database.connections.mongodb.database');
        $aliases = collect(['inventory', 'marketplace', 'platform'])
            ->map(fn ($alias) => config("database.connections.$alias.database"))->unique()->implode(', ');
        $this->line('Entorno: '.app()->environment());
        $this->line('MongoDB: '.$mongo.' (alias: '.$aliases.')');
        $this->line('SQL Server: '.config('database.connections.sqlsrv.database'));
    }

    private function reachable(): bool
    {
        foreach (['mongodb' => fn () => DB::connection('mongodb')->getDatabase()->command(['ping' => 1]),
            'sqlsrv' => fn () => DB::connection('sqlsrv')->selectOne('SELECT 1 AS ok')] as $name => $probe) {
            try {
                $probe();
            } catch (Throwable $e) {
                report($e);
                $this->error("No se pudo conectar con $name. Revisa la configuración; no se escribió nada.");

                return false;
            }
        }

        return true;
    }

    /** Prints the per-path status and returns true when nothing is pending and the base catalogs exist. */
    private function report(): bool
    {
        $rows = [];
        $pending = 0;
        foreach (self::MIGRATION_PATHS as $step) {
            $count = $this->pending($step['database'], $step['path']);
            $pending += $count;
            $rows[] = [$step['label'], $count === 0 ? 'OK' : $count.' pendientes'];
        }
        $catalogs = $this->catalogsPresent();
        $rows[] = ['Catálogos base (roles, permisos, campus)', $catalogs ? 'OK' : 'Pendientes'];
        $this->table(['Parte', 'Estado'], $rows);

        if ($pending === 0 && $catalogs) {
            $this->info('Instalación completa.');

            return true;
        }
        $this->warn('Pendientes: '.$pending.' migraciones'.($catalogs ? '' : ' y catálogos base').'. Ejecuta php artisan campus:install.');

        return false;
    }

    private function pending(?string $connection, string $path): int
    {
        $migrator = app('migrator');
        $repository = $migrator->getRepository();
        try {
            $repository->setSource($connection);
            $ran = $repository->repositoryExists() ? $repository->getRan() : [];
        } finally {
            $repository->setSource(null);
        }

        return count(array_diff(array_keys($migrator->getMigrationFiles(base_path($path))), $ran));
    }

    private function migrate(?string $connection, string $path): bool
    {
        return $this->call_('migrate', array_filter(['--database' => $connection, '--path' => $path, '--force' => true]));
    }

    private function catalogsPresent(): bool
    {
        try {
            $db = DB::connection('mongodb')->getDatabase();

            return $db->selectCollection('roles')->countDocuments() > 0
                && $db->selectCollection('permissions')->countDocuments() > 0
                && $db->selectCollection('campuses')->countDocuments() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /** Runs an Artisan command and shows its output only when it fails. */
    private function call_(string $command, array $parameters = []): bool
    {
        $code = Artisan::call($command, $parameters);
        if ($code !== 0) {
            $this->error("Falló «{$command}» (código {$code}). Salida:");
            $this->line(Artisan::output());

            return false;
        }

        return true;
    }
}
