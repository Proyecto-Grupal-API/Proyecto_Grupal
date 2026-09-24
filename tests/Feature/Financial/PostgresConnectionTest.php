<?php

use Illuminate\Support\Facades\DB;

test('financial tests use the PostgreSQL testing database', function () {
    $database = DB::connection('pgsql')
        ->selectOne('select current_database() as database_name')
        ->database_name;

    expect($database)->toBe('campus_digital_financial_testing');
});
