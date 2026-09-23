<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * Modulo 2.1 - Cuentas Wallet.
 *
 * Crea la coleccion principal de wallets del dominio financiero.
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection('mongodb')->create('wallets', function (Blueprint $collection) {
            $collection->unique('public_id');

            $collection->unique([
                'owner_type',
                'owner_id',
                'type',
            ]);

            $collection->index('owner_id');
            $collection->index('status');
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->dropIfExists('wallets');
    }
};