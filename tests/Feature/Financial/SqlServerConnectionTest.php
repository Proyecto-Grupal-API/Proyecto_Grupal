<?php

use Illuminate\Support\Facades\DB;

test('financial tests use the SQL Server testing database', function () {
    $database = DB::connection('sqlsrv')
        ->selectOne('SELECT DB_NAME() AS database_name')
        ->database_name;

    expect($database)->toBe('campus_digital_financial_testing');
});