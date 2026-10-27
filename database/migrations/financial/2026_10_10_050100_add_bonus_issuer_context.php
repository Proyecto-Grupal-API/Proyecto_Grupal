<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        Schema::connection('sqlsrv')->table('bonuses', function (Blueprint $t) {
            $t->string('issuer_scope_type', 20)->nullable();
            $t->string('issuer_scope_id', 100)->nullable();
            $t->index(['issuer_scope_type', 'issuer_scope_id'], 'bonus_issuer_context');
        });
    }

    public function down(): void
    {
        Schema::connection('sqlsrv')->table('bonuses', function (Blueprint $t) {
            $t->dropIndex('bonus_issuer_context');
            $t->dropColumn(['issuer_scope_type', 'issuer_scope_id']);
        });
    }
};
