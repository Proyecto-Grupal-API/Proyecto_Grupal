<?php

namespace Tests\Support;

use MongoDB\Database;

/** Explicit opt-in; absence keeps the original exclusive E1 guard unchanged. */
final class E1TestTarget
{
    private static ?string $authorizedRoot = null;

    /** T-01: true only after an approved "integrated-financial" activation (SQL attested separately). */
    private static bool $financialSql = false;

    public static function select(mixed $profile, bool $authorized): string
    {
        if ($profile === false || $profile === null || $profile === '' || $profile === 'isolated') { return 'isolated'; }
        E1IntegratedMongoTarget::require(in_array($profile, ['integrated', 'integrated-financial'], true) && $authorized);
        return 'integrated';
    }

    public static function profile(): string
    {
        return self::select(getenv('E1_TEST_PROFILE'), self::$authorizedRoot !== null);
    }

    /** Called only by the private bootstrap after a separately approved runtime profile. */
    public static function activateIntegrated(string $root, string $approvalFile): void
    {
        $real = realpath($approvalFile);
        E1IntegratedMongoTarget::require(self::$authorizedRoot === null && realpath($root) === $root
            && $real !== false && ! is_link($approvalFile) && ! str_starts_with($real, $root.'/')
            && (fileperms($real) & 0777) === 0600 && fileowner($real) === getmyuid());
        $approval = json_decode(file_get_contents($real), true, flags: JSON_THROW_ON_ERROR);
        E1IntegratedMongoTarget::require(($approval['runtime_execution_authorized'] ?? false) === true
            && ($approval['candidate_root'] ?? '') === $root
            && ($approval['guard_sha256'] ?? '') === hash_file('sha256', $root.'/tests/Support/E1MongoTestTarget.php')
            && ($approval['aliases'] ?? []) === E1IntegratedMongoTarget::ALIASES
            && in_array(getenv('E1_TEST_PROFILE'), ['integrated', 'integrated-financial'], true));
        if (getenv('E1_TEST_PROFILE') === 'integrated-financial') {
            E1FinancialSqlTarget::require(($approval['financial_sql'] ?? null) === ['database' => E1FinancialSqlTarget::DATABASE,
                'guard_sha256' => hash_file('sha256', $root.'/tests/Support/E1FinancialSqlTarget.php')]);
            E1FinancialSqlTarget::assertBeforeBootstrap();
            self::$financialSql = true;
        }
        E1MongoTestTarget::assertBeforeBootstrap($root);
        self::$authorizedRoot = $root;
    }

    public static function financialSql(): bool
    {
        return self::$financialSql && getenv('E1_TEST_PROFILE') === 'integrated-financial';
    }

    public static function requireIntegrated(): void
    {
        E1IntegratedMongoTarget::require(self::profile() === 'integrated' && self::$authorizedRoot !== null
            && realpath(base_path()) === self::$authorizedRoot);
    }

    public static function assertBeforeBootstrap(string $root): void
    {
        self::profile(); // Unknown or unapproved profiles fail before application creation.
        E1MongoTestTarget::assertBeforeBootstrap($root);
        if (self::profile() === 'integrated') { E1IntegratedMongoTarget::require($root === self::$authorizedRoot); }
    }

    public static function assertApplication(?Database $database = null): void
    {
        if (self::profile() === 'isolated') { E1MongoTestTarget::assertApplication($database); }
        else { E1IntegratedMongoTarget::assertApplication($database); }
    }

    public static function install(object $app): void
    {
        self::requireIntegrated();
        $app->afterBootstrapping(\Illuminate\Foundation\Bootstrap\LoadConfiguration::class, function ($app): void {
            self::requireIntegrated();
            E1IntegratedMongoTarget::require(! $app->resolved('db'));
            $dsn = (string) getenv('MONGODB_DSN');
            E1MongoTestTarget::assertUri($dsn);
            E1IntegratedMongoTarget::require(getenv('MONGODB_URI') === $dsn);
            $app['config']->set('database.connections.mongodb', [
                'driver' => 'mongodb', 'dsn' => $dsn, 'database' => E1MongoTestTarget::DATABASE,
                'host' => E1MongoTestTarget::HOST, 'port' => E1MongoTestTarget::PORT,
                'options' => ['database' => E1MongoTestTarget::DATABASE],
            ]);
            // Container extender runs before providers' resolving callbacks can use db.
            $app->extend('db', function ($previous, $app) {
                E1IntegratedMongoTarget::require($previous->getConnections() === []);
                return new E1IntegratedDatabaseManager($app, $app['db.factory']);
            });
        });
        $app->beforeBootstrapping(\Illuminate\Foundation\Bootstrap\BootProviders::class,
            fn () => E1IntegratedMongoTarget::assertApplication());
    }
}
