<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        $profiles = DB::connection('mongodb')->getCollection('student_profiles');
        $profiles->createIndex(['user_id' => 1], ['unique' => true]);
        $profiles->createIndex(['enrollment_number' => 1], ['unique' => true]);
    }

    public function down(): void
    {
        $profiles = DB::connection('mongodb')->getCollection('student_profiles');
        $profiles->dropIndex('enrollment_number_1');
        $profiles->dropIndex('user_id_1');
    }
};
