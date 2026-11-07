<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        DB::connection('mongodb')->getCollection('library_fines')->createIndex(['verified_payment_id' => 1], [
            'name' => 'library_fine_verified_payment_unique',
            'unique' => true,
            'partialFilterExpression' => ['verified_payment_id' => ['$type' => 'string']],
        ]);
    }

    public function down(): void
    {
        DB::connection('mongodb')->getCollection('library_fines')->dropIndex('library_fine_verified_payment_unique');
    }
};
