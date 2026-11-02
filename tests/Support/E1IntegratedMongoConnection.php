<?php

namespace Tests\Support;

use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Laravel\Connection;

/** Fixed target: cached handles cannot be redirected by configuration mutation. */
final class E1IntegratedMongoConnection extends Connection
{
    private function checkTarget(): void
    {
        E1IntegratedMongoTarget::assertRuntime(false);
        E1IntegratedMongoTarget::assertConnection($this->getConfig());
        $current = config('database.connections.'.$this->getName());
        E1IntegratedMongoTarget::assertConnection($current);
        E1IntegratedMongoTarget::require($current['dsn'] === $this->getConfig('dsn'));
    }

    public function getDatabase(?string $name = null): Database
    {
        $this->checkTarget();
        E1IntegratedMongoTarget::require($name === null || $name === E1MongoTestTarget::DATABASE);
        return parent::getDatabase($name);
    }

    public function getMongoDB()
    {
        return $this->getDatabase();
    }

    public function getClient(): ?\MongoDB\Client
    {
        $this->checkTarget();
        return parent::getClient();
    }

    public function getCollection($name): Collection
    {
        $this->checkTarget();
        return parent::getCollection($name);
    }

    public function table($table, $as = null)
    {
        $this->checkTarget();
        return parent::table($table, $as);
    }

    public function getSchemaBuilder()
    {
        $this->checkTarget();
        return parent::getSchemaBuilder();
    }

    public function setDatabaseName($database)
    {
        E1IntegratedMongoTarget::require($database === E1MongoTestTarget::DATABASE);
        return parent::setDatabaseName($database);
    }
}
