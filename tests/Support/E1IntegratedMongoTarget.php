<?php

namespace Tests\Support;

use MongoDB\Database;
use RuntimeException;
use Throwable;

/** Fixed four-alias testing policy. No provisioning, grants or production bindings. */
final class E1IntegratedMongoTarget
{
    public const ALIASES = ['mongodb', 'inventory', 'marketplace', 'platform'];

    public static function require(bool $condition): void
    {
        if (! $condition) { throw new RuntimeException('Blocked integrated E1 testing: target not accredited.'); }
    }

    public static function assertName(mixed $name): string
    {
        self::require(is_string($name) && in_array($name, self::ALIASES, true));
        return $name;
    }

    public static function assertConnection(array $config): void
    {
        self::require(array_diff(array_keys($config), ['driver', 'dsn', 'database', 'host', 'port',
            'options', 'username', 'password', 'url', 'name', 'prefix']) === []);
        E1MongoTestTarget::assertConfiguration('testing', 'mongodb', $config);
        self::require(($config['host'] ?? null) === E1MongoTestTarget::HOST
            && ($config['port'] ?? null) === E1MongoTestTarget::PORT
            && ($config['prefix'] ?? '') === ''
            && in_array($config['url'] ?? null, [null, ''], true)
            && in_array($config['password'] ?? null, [null, ''], true));
        if (isset($config['name'])) { self::assertName($config['name']); }
        // Reject duplicate/ambiguous URI options, not just parse_str's last value.
        $query = parse_url($config['dsn'], PHP_URL_QUERY);
        $expected = 'authSource='.E1MongoTestTarget::DATABASE.'&replicaSet='.E1MongoTestTarget::REPLICA_SET;
        $reverse = implode('&', array_reverse(explode('&', $expected)));
        self::require($query === $expected || $query === $reverse);
    }

    public static function assertMap(string $environment, string $default, array $connections, bool $complete = true): void
    {
        self::require($environment === 'testing' && $default === 'mongodb');
        foreach ($connections as $name => $config) {
            self::require(is_array($config));
            if (in_array($name, self::ALIASES, true)) { self::assertConnection($config); }
            else { self::require(($config['driver'] ?? null) !== 'mongodb'); }
        }
        $present = array_values(array_intersect(self::ALIASES, array_keys($connections)));
        self::require($complete ? $present === self::ALIASES : in_array('mongodb', $present, true));
    }

    public static function assertRuntime(bool $complete = true): void
    {
        E1TestTarget::requireIntegrated();
        self::assertMap(app()->environment(), config('database.default'), config('database.connections', []), $complete);
        self::require(! app()->configurationIsCached() && config('cache.default') === 'array'
            && config('session.driver') === 'array' && config('queue.default') === 'sync');
    }

    public static function attest(object $connection): Database
    {
        try {
            self::assertConnection($connection->getConfig());
            $database = $connection->getDatabase();
            self::require($database->getDatabaseName() === E1MongoTestTarget::DATABASE);
            $hello = json_decode(json_encode($database->command(['hello' => 1])->toArray()[0], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            $status = json_decode(json_encode($database->command(['connectionStatus' => 1, 'showPrivileges' => true])->toArray()[0], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            E1MongoTestTarget::assertEvidence($hello, $status['authInfo'] ?? []);
            return $database;
        } catch (Throwable) {
            throw new RuntimeException('Blocked integrated E1 testing: server attestation failed.');
        }
    }

    public static function assertApplication(?Database $expected = null): void
    {
        self::assertRuntime();
        $manager = app('db');
        self::require($manager instanceof E1IntegratedDatabaseManager);
        $allowed = E1TestTarget::financialSql() ? [...self::ALIASES, 'sqlsrv'] : self::ALIASES;
        self::require(array_diff(array_keys($manager->getConnections()), $allowed) === []);
        $found = $expected === null;
        foreach (self::ALIASES as $name) {
            $database = self::attest($manager->connection($name));
            $found = $found || $expected === $database;
        }
        self::require($found);
    }
}
