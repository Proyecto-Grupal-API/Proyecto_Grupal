<?php

namespace Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\E1IntegratedDatabaseManager;
use Tests\Support\E1IntegratedMongoTarget as Integrated;
use Tests\Support\E1MongoTestTarget as Original;
use Tests\Support\E1TestTarget as Profile;

/** Pure policy/denial tests: no Laravel application, driver object, database or filesystem writes. */
final class E1IntegratedMongoTargetTest extends TestCase
{
    private static function config(): array
    {
        return ['driver' => 'mongodb', 'dsn' => 'mongodb://campus_e1_test:synthetic@campus-e1-test-mongo:27018/campus_virtual_testing?authSource=campus_virtual_testing&replicaSet=rs-e1-testing',
            'database' => Original::DATABASE, 'host' => Original::HOST, 'port' => Original::PORT,
            'options' => ['database' => Original::DATABASE]];
    }

    private static function map(): array
    {
        return array_fill_keys(Integrated::ALIASES, self::config());
    }

    public function testFourAliasesHaveOneSyntheticTarget(): void
    {
        Integrated::assertMap('testing', 'mongodb', self::map());
        $this->addToAssertionCount(1);
    }

    public function testGuardedConnectionClassLoadsWithoutCreatingADriverClient(): void
    {
        $this->assertTrue(class_exists(\Tests\Support\E1IntegratedMongoConnection::class));
    }

    public function testAllOriginalIdentityAndPrivilegeChecksAreReused(): void
    {
        $hello = ['setName' => Original::REPLICA_SET, 'isWritablePrimary' => true,
            'me' => Original::HOST.':'.Original::PORT, 'hosts' => [Original::HOST.':'.Original::PORT]];
        $auth = ['authenticatedUsers' => [['user' => Original::USER, 'db' => Original::DATABASE]],
            'authenticatedUserRoles' => [['role' => Original::ROLE, 'db' => Original::DATABASE]],
            'authenticatedUserPrivileges' => [['resource' => ['db' => Original::DATABASE, 'collection' => ''], 'actions' => Original::ACTIONS]]];
        Original::assertEvidence($hello, $auth);
        $auth['authenticatedUserPrivileges'][0]['actions'][] = 'collMod';
        $this->expectException(RuntimeException::class);
        Original::assertEvidence($hello, $auth);
    }

    public static function invalidConfigurations(): array
    {
        $base = self::config(); $cases = [];
        foreach (['driver' => 'sqlsrv', 'database' => 'campus_virtual', 'host' => 'campus-mongo',
            'port' => 27017, 'options' => [], 'username' => 'root', 'password' => 'ambiguous',
            'url' => 'mongodb://other:27017', 'read' => [], 'write' => [], 'driver_options' => [],
            'prefix' => 'other_', 'name' => 'unexpected'] as $key => $value) {
            $cases[$key] = [[...$base, $key => $value]];
        }
        foreach (['driver', 'database', 'host', 'port', 'dsn', 'options'] as $key) {
            $value = $base; unset($value[$key]); $cases['missing '.$key] = [$value];
        }
        foreach (['host' => ['campus-e1-test-mongo'], 'port' => '27018'] as $key => $value) {
            $cases['ambiguous '.$key] = [[...$base, $key => $value]];
        }
        foreach (['url' => '0', 'password' => []] as $key => $value) {
            $cases['falsey '.$key] = [[...$base, $key => $value]];
        }
        foreach ([
            'missing password' => str_replace(':synthetic@', '@', $base['dsn']),
            'empty password' => str_replace(':synthetic@', ':@', $base['dsn']),
            'wrong host' => str_replace('campus-e1-test-mongo', 'campus-mongo', $base['dsn']),
            'wrong port' => str_replace(':27018', ':27017', $base['dsn']),
            'wrong user' => str_replace('campus_e1_test:', 'admin:', $base['dsn']),
            'wrong database' => str_replace('/campus_virtual_testing?', '/campus_virtual?', $base['dsn']),
            'wrong replica' => str_replace('rs-e1-testing', 'rs0', $base['dsn']),
            'wrong authsource' => str_replace('authSource=campus_virtual_testing', 'authSource=admin', $base['dsn']),
            'duplicate option' => $base['dsn'].'&replicaSet=rs-e1-testing',
            'timeout option' => $base['dsn'].'&serverSelectionTimeoutMS=1',
        ] as $key => $value) { $cases[$key] = [[...$base, 'dsn' => $value]]; }
        return $cases;
    }

    #[DataProvider('invalidConfigurations')]
    public function testUnsafeConfigurationIsRejected(array $config): void
    {
        $this->expectException(RuntimeException::class);
        Integrated::assertConnection($config);
    }

    public static function missingAliases(): array
    {
        return array_map(fn ($name) => [$name], Integrated::ALIASES);
    }

    #[DataProvider('missingAliases')]
    public function testEveryAliasIsMandatory(string $name): void
    {
        $map = self::map(); unset($map[$name]);
        $this->expectException(RuntimeException::class);
        Integrated::assertMap('testing', 'mongodb', $map);
    }

    public function testAdditionalMongoAliasIsRejectedEvenWithSameDestination(): void
    {
        $this->expectException(RuntimeException::class);
        Integrated::assertMap('testing', 'mongodb', [...self::map(), 'extra' => self::config()]);
    }

    public static function forbiddenNames(): array
    {
        return array_map(fn ($value) => [$value], ['sqlsrv', 'sqlite', 'mysql', 'pgsql', 'other', 'mongodb::read', 'inventory::write', '', ['mongodb']]);
    }

    #[DataProvider('forbiddenNames')]
    public function testManagerRejectsBeforeAnyDatabaseFactoryOrRuntimeCall(mixed $name): void
    {
        $container = new Container;
        $factory = new class($container) extends ConnectionFactory {
            public function make(array $config, $name = null) { throw new \LogicException('Factory must never be reached.'); }
        };
        $manager = new E1IntegratedDatabaseManager($container, $factory);
        $this->expectException(RuntimeException::class);
        $manager->connection($name);
    }

    public function testDynamicConfigurationCannotBypassManager(): void
    {
        $container = new Container;
        $manager = new E1IntegratedDatabaseManager($container, new ConnectionFactory($container));
        $this->expectException(RuntimeException::class);
        $manager->build(self::config());
    }

    public function testConnectUsingCannotBypassManager(): void
    {
        $container = new Container;
        $manager = new E1IntegratedDatabaseManager($container, new ConnectionFactory($container));
        $this->expectException(RuntimeException::class);
        $manager->connectUsing('mongodb', self::config());
    }

    public function testEveryAliasIsValidatedNotOnlyPrimary(): void
    {
        $map = self::map();
        $map['platform']['database'] = 'campus_digital';
        $this->expectException(RuntimeException::class);
        Integrated::assertMap('testing', 'mongodb', $map);
    }

    public function testDeclaredButUnopenedSqlIsNotAuthorizationToResolveIt(): void
    {
        Integrated::assertMap('testing', 'mongodb', [...self::map(), 'sqlsrv' => ['driver' => 'sqlsrv']]);
        $this->expectException(RuntimeException::class);
        Integrated::assertName('sqlsrv');
    }

    public static function profiles(): array
    {
        return [[null, false, 'isolated'], [false, false, 'isolated'], ['', false, 'isolated'],
            ['isolated', false, 'isolated'], ['integrated', true, 'integrated']];
    }

    #[DataProvider('profiles')]
    public function testExplicitSelection(mixed $mode, bool $authorized, string $expected): void
    {
        $this->assertSame($expected, Profile::select($mode, $authorized));
    }

    public static function invalidProfiles(): array
    {
        return [['integrated', false], ['INTEGRATED', true], ['production', true], ['unknown', false], [true, true]];
    }

    #[DataProvider('invalidProfiles')]
    public function testEnvironmentAloneDoesNotAuthorizeProfile(mixed $mode, bool $authorized): void
    {
        $this->expectException(RuntimeException::class);
        Profile::select($mode, $authorized);
    }

    public function testDevelopmentEnvironmentIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        Integrated::assertMap('local', 'mongodb', self::map());
    }

    public function testSqlDefaultIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        Integrated::assertMap('testing', 'sqlsrv', self::map());
    }
}
