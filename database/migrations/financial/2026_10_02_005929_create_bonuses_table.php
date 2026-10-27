<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        Schema::connection('sqlsrv')->create(
            'bonuses',
            function (Blueprint $table) {
                $table->id();

                $table->uuid('public_id')->unique();

                $table->string('beneficiary_type', 50);
                $table->string('beneficiary_id', 255);

                $table->string('issuer_type', 50);
                $table->string('issuer_id', 255);

                $table->string('type', 30);

                $table->bigInteger('original_amount_cents');
                $table->bigInteger('remaining_amount_cents');

                $table->string('currency', 3)
                    ->default('MXN');

                $table->string('status', 30);

                $table->dateTime('valid_from');
                $table->dateTime('expires_at');

                $table->boolean('combinable')
                    ->default(false);

                $table->string(
                    'external_reference',
                    255
                )->nullable();

                $table->timestamps();

                $table->index([
                    'beneficiary_type',
                    'beneficiary_id',
                ]);

                $table->index([
                    'issuer_type',
                    'issuer_id',
                ]);

                $table->index('type');
                $table->index('status');
                $table->index('expires_at');
                $table->index('external_reference');
            }
        );
    }

    public function down(): void
    {
        Schema::connection('sqlsrv')
            ->dropIfExists('bonuses');
    }
};
