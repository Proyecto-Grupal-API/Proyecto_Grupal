<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        $collection = DB::connection('mongodb')->getCollection('nfc_cards');

        $collection->createIndex(['replacement_of_card_id' => 1], [
            'name' => 'nfc_replacement_of_unique',
            'unique' => true,
            'partialFilterExpression' => ['replacement_of_card_id' => ['$type' => 'string']],
        ]);

        $collection->createIndex(['replaced_by_card_id' => 1], [
            'name' => 'nfc_replaced_by_lookup',
            'partialFilterExpression' => ['replaced_by_card_id' => ['$type' => 'string']],
        ]);
    }

    public function down(): void
    {
        $collection = DB::connection('mongodb')->getCollection('nfc_cards');
        $collection->dropIndex('nfc_replaced_by_lookup');
        $collection->dropIndex('nfc_replacement_of_unique');
    }
};
