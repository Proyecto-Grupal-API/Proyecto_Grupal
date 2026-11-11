<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Impedir ejecuciones fuera del entorno de pruebas.
        if (!app()->environment('testing')) {
            throw new \RuntimeException(
                'Las pruebas deben ejecutarse en el entorno testing.'
            );
        }

        // Evitar que distintas pruebas compartan el limite OAuth.
        static $requestIpSequence = 0;
        ++$requestIpSequence;

        $this->withServerVariables([
            'REMOTE_ADDR' => '127.1.'
                . intdiv($requestIpSequence, 254)
                . '.'
                . (($requestIpSequence % 254) + 1),
        ]);

        // Validar las conexiones antes de eliminar MongoDB.
        if (config('database.default') === 'mongodb') {

            $mongo = config('database.connections.mongodb');
            $sql = config('database.connections.sqlsrv');

            $dsn = (string) ($mongo['dsn'] ?? '');

            $host = strtolower((string) parse_url(
                $dsn,
                PHP_URL_HOST
            ));

            $uriDatabase = trim((string) parse_url(
                $dsn,
                PHP_URL_PATH
            ), '/');

            $hostsPermitidos = [
                'localhost',
                '127.0.0.1',
                'host.docker.internal',
            ];

            if (
                ($mongo['database'] ?? '') !== 'campus_virtual_testing' ||
                ($mongo['options']['database'] ?? '') !== 'campus_virtual_testing' ||
                !in_array($host, $hostsPermitidos, true) ||
                !in_array($uriDatabase, ['', 'campus_virtual_testing'], true) ||
                ($sql['database'] ?? '') !== 'campus_digital_financial_testing' ||
                !in_array(
                    strtolower((string) ($sql['host'] ?? '')),
                    $hostsPermitidos,
                    true
                ) ||
                !empty($sql['url'])
            ) {
                throw new \RuntimeException(
                    'Configuracion de testing no autorizada.'
                );
            }

            // Eliminar exclusivamente la base MongoDB de testing.
            DB::connection('mongodb')
                ->getClient()
                ->selectDatabase('campus_virtual_testing')
                ->drop();
        }
    }
}
