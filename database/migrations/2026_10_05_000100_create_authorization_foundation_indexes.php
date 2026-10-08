<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'mongodb';

    private function indexes(): array
    {
        return [
            'roles' => [['name' => 1], 'role_catalog_name_unique', ['unique' => true]],
            'permissions' => [['key' => 1], 'permission_key_unique', ['unique' => true]],
            'role_permissions' => [['role_key' => 1, 'permission_key' => 1], 'role_permission_unique', ['unique' => true]],
        ];
    }

    public function up(): void
    {
        foreach ($this->indexes() as $collection => [$keys, $name, $options]) {
            DB::connection('mongodb')->getCollection($collection)->createIndex($keys, ['name' => $name, ...$options]);
        }
        foreach (['role_assignments' => ['user_id' => 1, 'role_key' => 1, 'scope_type' => 1, 'scope_id' => 1],
            'business_memberships' => ['user_id' => 1, 'business_id' => 1]] as $name => $identity) {
            $collection = DB::connection('mongodb')->getCollection($name);
            $collection->createIndex($identity, ['name' => 'current_identity_unique', 'unique' => true,
                'partialFilterExpression' => ['is_current' => true]]);
            $collection->createIndex([...$identity, 'generation' => 1], ['name' => 'identity_generation_unique', 'unique' => true]);
            $collection->createIndex(['user_id' => 1, 'is_current' => 1, 'status' => 1, 'ends_at' => 1], ['name' => 'subject_current_status_expiry']);
            $context = $name === 'role_assignments' ? ['scope_type' => 1, 'scope_id' => 1] : ['business_id' => 1];
            $collection->createIndex([...$context, 'is_current' => 1, 'status' => 1], ['name' => 'context_current_status']);
        }
    }

    public function down(): void
    {
        // Rollback removes only our indexes, never assignment history or users.
        foreach ($this->indexes() as $collection => [$keys, $name]) {
            DB::connection('mongodb')->getCollection($collection)->dropIndex($name);
        }
        foreach (['role_assignments', 'business_memberships'] as $name) {
            foreach (['current_identity_unique', 'identity_generation_unique', 'subject_current_status_expiry', 'context_current_status'] as $index) {
                DB::connection('mongodb')->getCollection($name)->dropIndex($index);
            }
        }
    }
};
