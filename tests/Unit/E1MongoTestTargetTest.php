<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\E1MongoTestTarget as Target;

/** Pure array/string verification: no Laravel bootstrap, DB client, environment mutation or files. */
final class E1MongoTestTargetTest extends TestCase
{
    private static function uri(): string
    {
        return 'mongodb://campus_e1_test:synthetic-unit-only@campus-e1-test-mongo:27018/campus_virtual_testing?authSource=campus_virtual_testing&replicaSet=rs-e1-testing';
    }

    private static function configuration(): array
    {
        return ['driver' => 'mongodb', 'database' => Target::DATABASE, 'dsn' => self::uri(),
            'host' => Target::HOST, 'port' => Target::PORT, 'options' => ['database' => Target::DATABASE]];
    }

    private static function evidence(): array
    {
        return [
            ['setName' => Target::REPLICA_SET, 'isWritablePrimary' => true,
                'me' => Target::HOST.':'.Target::PORT, 'hosts' => [Target::HOST.':'.Target::PORT]],
            ['authenticatedUsers' => [['user' => Target::USER, 'db' => Target::DATABASE]],
                'authenticatedUserRoles' => [['role' => Target::ROLE, 'db' => Target::DATABASE]],
                'authenticatedUserPrivileges' => [['resource' => ['db' => Target::DATABASE, 'collection' => ''], 'actions' => Target::ACTIONS]]],
        ];
    }

    public function test_complete_explicit_profile_is_accepted(): void
    {
        self::assertSame(27018, Target::PORT);
        Target::assertConfiguration('testing', 'mongodb', self::configuration());
        Target::assertEvidence(...self::evidence());
        $this->addToAssertionCount(1);
    }

    public static function unsafeUris(): iterable
    {
        foreach (['loopback' => ['campus-e1-test-mongo', '127.0.0.1'],
            'integration' => ['campus-e1-test-mongo', 'campus-integracion-mongo'],
            'wrong user' => ['campus_e1_test:', 'admin:'],
            'wrong auth database' => ['authSource=campus_virtual_testing', 'authSource=admin'],
            'development replica' => ['rs-e1-testing', 'rs0'],
            'wrong database' => ['/campus_virtual_testing?', '/campus_virtual?'],
            'override option' => ['&replicaSet=', '&directConnection=true&replicaSet='],
            'missing password' => [':synthetic-unit-only@', '@'],
            'wrong port' => [':27018/', ':27017/'],
            'implicit default port' => [':27018/', '/']] as $name => [$from, $to]) {
            yield $name => [str_replace($from, $to, self::uri())];
        }
        yield 'missing information' => [''];
    }

    #[DataProvider('unsafeUris')]
    public function test_uri_fails_closed(string $uri): void
    {
        $this->expectException(RuntimeException::class);
        Target::assertUri($uri);
    }

    public static function unsafeConfigurations(): iterable
    {
        foreach ([['database', 'campus_virtual_integracion_testing'], ['driver', 'sqlsrv'],
            ['username', 'root'], ['password', 'synthetic'], ['url', 'override'],
            ['options', ['database' => 'campus_virtual']], ['host', 'localhost'], ['port', 27017], ['dsn', '']] as [$key, $value]) {
            yield $key => [[$key => $value]];
        }
    }

    #[DataProvider('unsafeConfigurations')]
    public function test_connection_override_fails_closed(array $changes): void
    {
        $this->expectException(RuntimeException::class);
        Target::assertConfiguration('testing', 'mongodb', array_replace(self::configuration(), $changes));
    }

    public function test_missing_connection_port_fails_closed(): void
    {
        $configuration = self::configuration();
        unset($configuration['port']);
        $this->expectException(RuntimeException::class);
        Target::assertConfiguration('testing', 'mongodb', $configuration);
    }

    public static function unsafeEvidence(): iterable
    {
        [$hello, $auth] = self::evidence();
        foreach (['setName' => 'rs0', 'isWritablePrimary' => false, 'me' => 'other:'.Target::PORT,
            'hosts' => [Target::HOST.':'.Target::PORT, 'other:'.Target::PORT], 'arbiters' => ['other:'.Target::PORT]] as $key => $value) {
            yield $key => [array_replace($hello, [$key => $value]), $auth];
        }
        yield 'old primary port' => [array_replace($hello, ['me' => Target::HOST.':27017']), $auth];
        yield 'old member port' => [array_replace($hello, ['hosts' => [Target::HOST.':27017']]), $auth];
        yield 'anonymous' => [$hello, array_replace($auth, ['authenticatedUsers' => []])];
        yield 'administrator' => [$hello, array_replace($auth, ['authenticatedUserRoles' => [['role' => 'root', 'db' => 'admin']]])];
        yield 'no privilege information' => [$hello, array_replace($auth, ['authenticatedUserPrivileges' => []])];
        foreach (['wrong database' => ['db' => 'campus_virtual', 'collection' => ''],
            'global resource' => ['anyResource' => true], 'collection only' => ['db' => Target::DATABASE, 'collection' => 'users']] as $name => $resource) {
            yield $name => [$hello, array_replace($auth, ['authenticatedUserPrivileges' => [['resource' => $resource, 'actions' => Target::ACTIONS]]])];
        }
        foreach (['extra action' => [...Target::ACTIONS, 'createUser'], 'missing action' => ['find']] as $name => $actions) {
            yield $name => [$hello, array_replace($auth, ['authenticatedUserPrivileges' => [['resource' => ['db' => Target::DATABASE, 'collection' => ''], 'actions' => $actions]]])];
        }
    }

    #[DataProvider('unsafeEvidence')]
    public function test_live_evidence_policy_fails_closed(array $hello, array $auth): void
    {
        $this->expectException(RuntimeException::class);
        Target::assertEvidence($hello, $auth);
    }

    public function test_wrong_environment_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        Target::assertConfiguration('local', 'mongodb', self::configuration());
    }

    public function test_missing_configuration_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        Target::assertConfiguration('testing', 'mongodb', []);
    }

    public function test_failure_message_does_not_expose_input(): void
    {
        try {
            Target::assertUri('mongodb://private-user:private-value@unapproved');
            $this->fail('Unsafe URI accepted.');
        } catch (RuntimeException $error) {
            self::assertStringNotContainsString('private', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }
}
