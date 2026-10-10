<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        $db=DB::connection('mongodb')->getMongoDB();
        $db->selectCollection('campus_authorization_state')->createIndex(['key'=>1],['unique'=>true,'name'=>'campus_auth_key_unique']);
        $db->selectCollection('campus_authorization_contexts')->createIndex(['type'=>1,'reference'=>1],['unique'=>true,'name'=>'campus_auth_context_unique']);
    }
    public function down(): void {
        // Historical authorization evidence is retained when the integration is disabled.
        DB::connection('mongodb')->getMongoDB()->selectCollection('campus_authorization_state')->dropIndex('campus_auth_key_unique');
        DB::connection('mongodb')->getMongoDB()->selectCollection('campus_authorization_contexts')->dropIndex('campus_auth_context_unique');
    }
};
