<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        if (! Schema::connection('mongodb')->hasTable('academic_status_history')) Schema::connection('mongodb')->create('academic_status_history', function (Blueprint $collection) {
            $collection->index('student_profile_id');
            $collection->index(['student_profile_id', 'changed_at']);
            $collection->index('changed_by');
        });
        if (! Schema::connection('mongodb')->hasTable('consents')) Schema::connection('mongodb')->create('consents', function (Blueprint $collection) {
            $collection->index('user_id');
            $collection->index(['user_id', 'type', 'created_at']);
            $collection->index('actor_id');
            $collection->index('revokes_consent_id');
        });
        if (! Schema::connection('mongodb')->hasTable('communication_preferences')) Schema::connection('mongodb')->create('communication_preferences', function (Blueprint $collection) {
            $collection->unique('user_id');
            $collection->index('updated_by');
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->dropIfExists('communication_preferences');
        Schema::connection('mongodb')->dropIfExists('consents');
        Schema::connection('mongodb')->dropIfExists('academic_status_history');
    }
};
