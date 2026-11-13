<?php

use Tests\Support\E1FinancialSqlTarget as Sql;
use Tests\Support\E1TestTarget;

function financialSqlConfig(array $override = []): array
{
    return [...['driver' => 'sqlsrv', 'host' => 'sqlserver', 'port' => '1433', 'database' => Sql::DATABASE,
        'username' => 'campus_financial_eval', 'password' => 'synthetic-unit-only', 'prefix' => '', 'encrypt' => 'yes',
        'trust_server_certificate' => 'true'], ...$override];
}

test('the accredited financial SQL configuration and evidence are accepted', function () {
    Sql::assertConfig(financialSqlConfig());
    Sql::assertEvidence(['db' => Sql::DATABASE, 'login_name' => 'campus_financial_eval', 'sysadmin' => 0, 'server_create' => 0]);
    expect(true)->toBeTrue();
});

test('financial SQL configuration rejects anything outside the dedicated isolated destination', function (array $override) {
    expect(fn () => Sql::assertConfig(financialSqlConfig($override)))->toThrow(RuntimeException::class);
})->with([
    'production database' => [['database' => 'campus_digital_financial']],
    'historical loopback publication' => [['host' => '127.0.0.1']],
    'any-address publication' => [['host' => '0.0.0.0']],
    'localhost' => [['host' => 'localhost']],
    'host gateway' => [['host' => 'host.containers.internal']],
    'sa login' => [['username' => 'sa']],
    'empty login' => [['username' => '']],
    'url fallback' => [['url' => 'sqlsrv://other']],
    'unencrypted' => [['encrypt' => 'no']],
    'other driver' => [['driver' => 'mongodb']],
    'prefix' => [['prefix' => 'x_']],
]);

test('runtime evidence rejects privileged or misdirected SQL sessions', function (array $row) {
    expect(fn () => Sql::assertEvidence($row))->toThrow(RuntimeException::class);
})->with([
    'sysadmin' => [['db' => Sql::DATABASE, 'login_name' => 'campus_financial_eval', 'sysadmin' => 1, 'server_create' => 0]],
    'server database creation' => [['db' => Sql::DATABASE, 'login_name' => 'campus_financial_eval', 'sysadmin' => 0, 'server_create' => 1]],
    'other database' => [['db' => 'master', 'login_name' => 'campus_financial_eval', 'sysadmin' => 0, 'server_create' => 0]],
    'sa' => [['db' => Sql::DATABASE, 'login_name' => 'sa', 'sysadmin' => 0, 'server_create' => 0]],
    'missing evidence' => [[]],
]);

test('the financial profile is never selected without the private approval', function () {
    expect(fn () => E1TestTarget::select('integrated-financial', false))->toThrow(RuntimeException::class)
        ->and(E1TestTarget::select('isolated', false))->toBe('isolated')
        ->and(E1TestTarget::financialSql())->toBeFalse();
});
