<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        DB::connection('mongodb')->getCollection('business_owner_claims')->createIndex(
            ['business_id' => 1], ['name' => 'initial_business_owner_unique', 'unique' => true]
        );
        DB::connection('mongodb')->getCollection('business_owner_provisions')->createIndex(
            ['operation_id' => 1], ['name' => 'owner_operation_unique', 'unique' => true]
        );
    }

    public function down(): void
    {
        DB::connection('mongodb')->getCollection('business_owner_claims')->dropIndex('initial_business_owner_unique');
        DB::connection('mongodb')->getCollection('business_owner_provisions')->dropIndex('owner_operation_unique');
    }
};
