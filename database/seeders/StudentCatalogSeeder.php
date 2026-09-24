<?php

namespace Database\Seeders;

use App\Models\AcademicProgram;
use App\Models\Campus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StudentCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $campus = Campus::firstOrCreate(['code' => 'CENTRAL'], ['name' => 'Campus Central', 'is_active' => true]);
        AcademicProgram::firstOrCreate(
            ['code' => 'ISC', 'campus_id' => $campus->getKey()],
            ['name' => 'Ingeniería de Sistemas', 'is_active' => true]
        );
        AcademicProgram::firstOrCreate(
            ['code' => 'ADM', 'campus_id' => $campus->getKey()],
            ['name' => 'Administración', 'is_active' => true]
        );

        DB::connection('mongodb')->getCollection('campuses')->createIndex(['code' => 1], ['unique' => true]);
        DB::connection('mongodb')->getCollection('academic_programs')->createIndex(['code' => 1, 'campus_id' => 1], ['unique' => true]);
        DB::connection('mongodb')->getCollection('student_profiles')->createIndex(['enrollment_number' => 1], ['unique' => true]);
        DB::connection('mongodb')->getCollection('student_profiles')->createIndex(['user_id' => 1], ['unique' => true]);
        DB::connection('mongodb')->getCollection('oauth_service_clients')->createIndex(['client_id' => 1], ['unique' => true]);
        DB::connection('mongodb')->getCollection('event_outbox')->createIndex(['event_id' => 1], ['unique' => true]);
        DB::connection('mongodb')->getCollection('event_outbox')->createIndex(['published_at' => 1, 'occurred_at' => 1]);
    }
}
