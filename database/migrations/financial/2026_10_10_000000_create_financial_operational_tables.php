<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        $schema = Schema::connection('sqlsrv');
        $schema->create('financial_queue_jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        $schema->create('financial_queue_failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
        $schema->create('financial_scheduler_cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration')->index();
        });
        $schema->create('financial_scheduler_cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration')->index();
        });
    }

    public function down(): void
    {
        foreach (['financial_scheduler_cache_locks', 'financial_scheduler_cache',
            'financial_queue_failed_jobs', 'financial_queue_jobs'] as $table) {
            Schema::connection('sqlsrv')->dropIfExists($table);
        }
    }
};
