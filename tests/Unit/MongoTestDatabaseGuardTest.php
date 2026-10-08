<?php

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Tests\TestCase;

class MongoSetupOrderProbe extends TestCase
{
    public bool $parentSetupReached = false;

    protected function refreshApplication()
    {
        $this->app = new Application;
        $this->app->instance('env', 'testing');
        $this->app->instance('config', new Repository(['database' => ['default' => 'mongodb']]));
    }

    protected function mongoTestDatabaseName(): string
    {
        return 'production_database_example';
    }

    protected function setUpTheTestEnvironment(): void
    {
        $this->parentSetupReached = true;
    }

    public function runSetupProbe(): void
    {
        $this->setUp();
    }
}

test('the test database guard permits only the configured Mongo testing database', function () {
    TestCase::assertSafeMongoDatabaseName('campus_virtual_testing');

    expect(true)->toBeTrue();
});

test('the test database guard rejects other names before a destructive operation', function (string $databaseName) {
    $destructiveCallReached = false;

    try {
        TestCase::assertSafeMongoDatabaseName($databaseName);
        $destructiveCallReached = true;
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())
            ->toContain('Blocked destructive test database operation', $databaseName);
    }

    expect($destructiveCallReached)->toBeFalse();
})->with(['campus_virtual', 'campus_virtual_test', 'production', 'production_database_example']);

test('an unsafe resolved database is rejected before parent setup can initialize testing traits', function () {
    $previousContainer = Container::getInstance();
    $probe = new MongoSetupOrderProbe('runSetupProbe');

    try {
        try {
            $probe->runSetupProbe();
            $this->fail('The unsafe MongoDB target must be rejected before parent setup.');
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())
                ->toContain('Blocked destructive test database operation', 'production_database_example');
        }

        expect($probe->parentSetupReached)->toBeFalse();
    } finally {
        Container::setInstance($previousContainer);
    }
});
