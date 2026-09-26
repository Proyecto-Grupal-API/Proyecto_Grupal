<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs');
        }
        Schema::table('jobs', function (Blueprint $collection) {
            $collection->index(['queue', 'reserved_at']);
        });
        if (! Schema::hasTable('job_batches')) {
            Schema::create('job_batches');
        }
        if (! Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs');
        }
        Schema::table('failed_jobs', function (Blueprint $collection) {
            $collection->unique('uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('jobs');
    }
};
