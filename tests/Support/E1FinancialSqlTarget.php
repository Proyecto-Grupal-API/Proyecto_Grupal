<?php

namespace Tests\Support;

use RuntimeException;
use Throwable;

/**
 * T-01: SQL Server attestation for the opt-in "integrated-financial" test profile.
 * It adds one SQL destination to the integrated MongoDB profile; it never relaxes the existing profiles.
 * Accepted only: the dedicated testing database, a non-loopback private host (the historical server is
 * published on 0.0.0.0:1433), encryption, no URL fallback and a non-sa login proven at runtime to lack
 * sysadmin and server-wide database creation.
 */
final class E1FinancialSqlTarget
{
    public const DATABASE = 'campus_digital_financial_testing';

    public const LOOPBACK = ['', 'localhost', '127.0.0.1', '0.0.0.0', '::1', '[::1]', 'host.containers.internal', 'host.docker.internal'];

    public static function require(bool $condition): void
    {
        if (! $condition) {
            throw new RuntimeException('Blocked financial E1 testing: SQL target not accredited.');
        }
    }

    /** Before application boot: environment only, no connection, no secrets in errors. */
    public static function assertBeforeBootstrap(): void
    {
        self::require(getenv('SQLSRV_DATABASE') === self::DATABASE
            && ! in_array(strtolower((string) getenv('SQLSRV_HOST')), self::LOOPBACK, true)
            && ! in_array(strtolower((string) getenv('SQLSRV_USERNAME')), ['', 'sa'], true)
            && ! getenv('DB_URL'));
    }

    public static function assertConfig(array $config): void
    {
        $encrypt = $config['encrypt'] ?? null;
        self::require(($config['driver'] ?? null) === 'sqlsrv'
            && ($config['database'] ?? null) === self::DATABASE
            && empty($config['url'])
            && ($config['prefix'] ?? '') === ''
            && ! in_array(strtolower((string) ($config['host'] ?? '')), self::LOOPBACK, true)
            && ! in_array(strtolower((string) ($config['username'] ?? '')), ['', 'sa'], true)
            && in_array($encrypt, ['yes', 'true', true, 'strict'], true));
    }

    /** $row: DB_NAME() AS db, SUSER_SNAME() AS login_name, server_create (count of server-wide create/alter/control permissions), sysadmin. */
    public static function assertEvidence(array $row): void
    {
        self::require(($row['db'] ?? null) === self::DATABASE
            && strtolower((string) ($row['login_name'] ?? 'sa')) !== 'sa'
            && (int) ($row['sysadmin'] ?? 1) === 0
            && (int) ($row['server_create'] ?? 1) === 0);
    }

    public static function attest(object $connection): void
    {
        try {
            self::assertConfig($connection->getConfig());
            $row = (array) $connection->selectOne("SELECT DB_NAME() AS db, SUSER_SNAME() AS login_name, "
                ."(SELECT COUNT(*) FROM fn_my_permissions(NULL, 'SERVER') WHERE permission_name IN "
                ."('CREATE ANY DATABASE', 'ALTER ANY DATABASE', 'CONTROL SERVER')) AS server_create, IS_SRVROLEMEMBER('sysadmin') AS sysadmin");
            self::assertEvidence($row);
        } catch (Throwable) {
            throw new RuntimeException('Blocked financial E1 testing: SQL attestation failed.');
        }
    }
}
