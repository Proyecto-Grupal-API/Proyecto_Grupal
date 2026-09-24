<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        Schema::connection('pgsql')->create(
            'financial_transactions',
            function (Blueprint $table) {
                $table->id();

                $table->uuid('public_id')->unique();

                $table->string('idempotency_key', 255)->unique();

                $table->string('status', 30);

                $table->string('reference_type', 100)->nullable();
                $table->string('reference_id', 255)->nullable();

                $table->uuid('original_transaction_id')->nullable();

                $table->jsonb('metadata')->nullable();

                $table->timestamps();

                $table->index('status');

                $table->index([
                    'reference_type',
                    'reference_id',
                ]);

                $table->index('original_transaction_id');
            }
        );
    }

    public function down(): void
    {
        Schema::connection('pgsql')
            ->dropIfExists('financial_transactions');
    }
};