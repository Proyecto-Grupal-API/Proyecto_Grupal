<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
       Schema::connection('sqlsrv')->create('wallets', function (Blueprint $table) {
            $table->id();

            $table->uuid('public_id')->unique();

            $table->string('owner_type', 50);
            $table->string('owner_id', 255);

            $table->string('type', 50);
            $table->string('currency', 3)->default('MXN');
            $table->string('status', 30);

            $table->bigInteger('available_balance_cents')->default(0);
            $table->bigInteger('held_balance_cents')->default(0);

            $table->timestamps();

            $table->unique([
                'owner_type',
                'owner_id',
                'type',
            ]);

            $table->index('owner_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::connection('sqlsrv')->dropIfExists('wallets');
    }
};