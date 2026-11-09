<?php

namespace Tests\Support;

use Illuminate\Database\DatabaseManager;
use UnitEnum;

/** Test-only resolver. Reject before constructing a connection, including PDO. */
final class E1IntegratedDatabaseManager extends DatabaseManager
{
    public function connection($name = null)
    {
        $name ??= $this->getDefaultConnection();
        E1IntegratedMongoTarget::assertName($name);
        E1IntegratedMongoTarget::assertRuntime(false);
        $connection = parent::connection($name);
        E1IntegratedMongoTarget::require($connection instanceof E1IntegratedMongoConnection);
        E1IntegratedMongoTarget::attest($connection);
        return $connection;
    }

    protected function makeConnection($name)
    {
        E1IntegratedMongoTarget::assertName($name);
        $config = $this->configuration($name);
        E1IntegratedMongoTarget::assertConnection($config);
        // Never delegate to arbitrary extensions, SQL factories or URL parsers after validation.
        try {
            return new E1IntegratedMongoConnection([...$config, 'name' => $name]);
        } catch (\Throwable) {
            throw new \RuntimeException('Blocked integrated E1 testing: connection construction failed.');
        }
    }

    public function build(array $config)
    {
        throw new \RuntimeException('Dynamic connections are forbidden in integrated E1 testing.');
    }

    public function connectUsing(UnitEnum|string $name, array $config, bool $force = false)
    {
        throw new \RuntimeException('Dynamic connections are forbidden in integrated E1 testing.');
    }
}
