<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        // Also repair integration databases initialized from the identity-only migration.
        Schema::connection('mongodb')->table('users', function (Blueprint $collection) {
            $collection->unique('matricula', options: ['partialFilterExpression' => ['matricula' => ['$type' => 'string']]]);
        });
    }

    public function down(): void
    {
        // The base users migration owns this constraint; never remove it on repair rollback.
    }
};
