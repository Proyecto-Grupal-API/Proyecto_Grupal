<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        DB::connection('sqlsrv')->statement(
            'DROP INDEX topups_folio_unique ON topups'
        );

        DB::connection('sqlsrv')->statement(
            'CREATE UNIQUE INDEX topups_folio_unique
             ON topups (folio)
             WHERE folio IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::connection('sqlsrv')->statement(
            'DROP INDEX topups_folio_unique ON topups'
        );

        DB::connection('sqlsrv')->statement(
            'CREATE UNIQUE INDEX topups_folio_unique
             ON topups (folio)'
        );
    }
};
