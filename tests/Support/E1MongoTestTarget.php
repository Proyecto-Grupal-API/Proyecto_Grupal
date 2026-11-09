<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use MongoDB\Database;
use RuntimeException;
use Throwable;

/** Fail-closed testing profile. This does not provision or attest infrastructure. */
final class E1MongoTestTarget
{
    public const DATABASE = 'campus_virtual_testing';
    public const HOST = 'campus-e1-test-mongo';
    public const PORT = 27018;
    public const REPLICA_SET = 'rs-e1-testing';
    public const USER = 'campus_e1_test';
    public const ROLE = 'campus_e1_testing';
    public const ACTIONS = ['createCollection', 'createIndex', 'dropCollection', 'dropDatabase', 'dropIndex',
        'find', 'insert', 'killCursors', 'listCollections', 'listIndexes', 'remove', 'update'];

    private static function require(bool $condition): void
    {
        if (! $condition) {
            throw new RuntimeException('Blocked E1 testing: isolated MongoDB target is not attested.');
        }
    }

    public static function assertUri(string $uri): void
    {
        $parts = parse_url($uri);
        self::require(is_array($parts));
        parse_str($parts['query'] ?? '', $options);
        self::require(($parts['scheme'] ?? '') === 'mongodb'
            && ($parts['host'] ?? '') === self::HOST && ($parts['port'] ?? null) === self::PORT
            && rawurldecode($parts['user'] ?? '') === self::USER
            && ($parts['pass'] ?? '') !== '' && ! isset($parts['fragment'])
            && ($parts['path'] ?? '') === '/'.self::DATABASE
            && ($options['authSource'] ?? '') === self::DATABASE
            && ($options['replicaSet'] ?? '') === self::REPLICA_SET
            && array_diff(array_keys($options), ['authSource', 'replicaSet']) === []);
    }

    /** Runs before application boot: no DB calls, dotenv reads or secrets in errors. */
    public static function assertBeforeBootstrap(string $root): void
    {
        self::require(getenv('E1_TEST_MONGO_ENABLE') === '1' && getenv('APP_ENV') === 'testing');
        self::assertUri((string) getenv('MONGODB_DSN'));
        self::require(! is_file($root.'/.env') && ! is_file($root.'/.env.testing')
            && ! is_file($root.'/bootstrap/cache/config.php')
            && ! getenv('APP_CONFIG_CACHE') && ! getenv('DB_URL'));
    }

    /** Pure policy, also used by unit tests without bootstrapping Laravel. */
    public static function assertConfiguration(string $environment, string $default, array $connection): void
    {
        self::require($environment === 'testing' && $default === 'mongodb'
            && ($connection['driver'] ?? '') === 'mongodb'
            && ($connection['database'] ?? '') === self::DATABASE
            && ($connection['options'] ?? []) === ['database' => self::DATABASE]
            && in_array($connection['username'] ?? null, [null, '', self::USER], true)
            && empty($connection['password']) && empty($connection['url']));
        self::assertUri((string) ($connection['dsn'] ?? ''));
        self::require(in_array($connection['host'] ?? null, [null, '', self::HOST], true)
            && (int) ($connection['port'] ?? 0) === self::PORT);
    }

    public static function assertEvidence(array $hello, array $auth): void
    {
        self::require(($hello['setName'] ?? '') === self::REPLICA_SET
            && ($hello['isWritablePrimary'] ?? false) === true
            && ($hello['me'] ?? '') === self::HOST.':'.self::PORT
            && ($hello['hosts'] ?? []) === [self::HOST.':'.self::PORT]
            && empty($hello['passives']) && empty($hello['arbiters'])
            && ($auth['authenticatedUsers'] ?? []) === [['user' => self::USER, 'db' => self::DATABASE]]
            && ($auth['authenticatedUserRoles'] ?? []) === [['role' => self::ROLE, 'db' => self::DATABASE]]);
        $actions = [];
        $privileges = $auth['authenticatedUserPrivileges'] ?? [];
        self::require(is_array($privileges) && $privileges !== []);
        foreach ($privileges as $privilege) {
            self::require(($privilege['resource'] ?? []) === ['db' => self::DATABASE, 'collection' => '']
                && is_array($privilege['actions'] ?? null));
            $actions = [...$actions, ...$privilege['actions']];
        }
        $actions = array_values(array_unique($actions));
        $expected = self::ACTIONS;
        sort($actions);
        sort($expected);
        self::require($actions === $expected);
    }

    /** Only diagnostic commands; call again at every testing cleanup/fixture boundary. */
    public static function assertApplication(?Database $database = null): void
    {
        self::assertConfiguration(app()->environment(), config('database.default'), config('database.connections.mongodb', []));
        self::require(! app()->configurationIsCached()
            && config('cache.default') === 'array' && config('session.driver') === 'array'
            && config('queue.default') === 'sync');
        // Original E1 has no secondary MongoDB consumer. Refuse newly introduced connections.
        foreach (config('database.connections', []) as $name => $connection) {
            self::require($name === 'mongodb' || ($connection['driver'] ?? '') !== 'mongodb');
        }
        self::require(array_diff(array_keys(DB::getConnections()), ['mongodb']) === []);
        try {
            $connection = DB::connection('mongodb');
            self::assertConfiguration(app()->environment(), config('database.default'), $connection->getConfig());
            $resolved = $connection->getDatabase();
            self::require($database === null || $database === $resolved);
            self::require($resolved->getDatabaseName() === self::DATABASE);
            $hello = json_decode(json_encode($resolved->command(['hello' => 1])->toArray()[0], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            $status = json_decode(json_encode($resolved->command(['connectionStatus' => 1, 'showPrivileges' => true])->toArray()[0], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            self::assertEvidence($hello, $status['authInfo'] ?? []);
        } catch (Throwable) {
            // Deliberately do not retain the driver error: it may contain a credentialed URI.
            throw new RuntimeException('Blocked E1 testing: MongoDB attestation failed.');
        }
    }
}
