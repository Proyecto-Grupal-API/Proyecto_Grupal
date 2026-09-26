<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users');
        }
        Schema::table('users', function (Blueprint $collection) {
            $collection->unique('email');
            $collection->unique('matricula', options: ['partialFilterExpression' => ['matricula' => ['$type' => 'string']]]);
        });
        if (! Schema::hasTable('password_reset_tokens')) {
            Schema::create('password_reset_tokens');
        }
        Schema::table('password_reset_tokens', function (Blueprint $collection) {
            $collection->unique('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
