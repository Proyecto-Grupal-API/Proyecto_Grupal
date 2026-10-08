<?php

namespace Database\Seeders;

use App\Enums\StudentStatus;
use App\Models\AcademicProgram;
use App\Models\AcademicStatusHistory;
use App\Models\Campus;
use App\Models\CommunicationPreference;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

class StudentServicesSeeder extends Seeder
{
    public function run(): void
    {
        $campus = Campus::where('code', 'CENTRAL')->firstOrFail();
        $program = AcademicProgram::where('code', 'ISC')->where('campus_id', (string) $campus->getKey())->firstOrFail();
        foreach ([
            ['active.student@example.com', 'Estudiante Activo', 'A2026001', StudentStatus::Active, 'Inscripción vigente'],
            ['graduate.student@example.com', 'Estudiante Egresado', 'A2026002', StudentStatus::Graduated, 'Plan de estudios concluido'],
            ['suspended.student@example.com', 'Estudiante Suspendido', 'A2026003', StudentStatus::Suspended, 'Suspensión académica registrada'],
            ['restricted.student@example.com', 'Estudiante Restringido', 'A2026004', StudentStatus::Restricted, 'Restricción administrativa registrada'],
        ] as [$email, $name, $enrollment, $status, $reason]) {
            $user = User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => bcrypt('password'), 'email_verified_at' => now()]);
            $profile = StudentProfile::updateOrCreate(['user_id' => (string) $user->getKey()], ['enrollment_number' => $enrollment, 'campus_id' => (string) $campus->getKey(), 'academic_program_id' => (string) $program->getKey(), 'current_semester' => 8, 'academic_status' => $status->value]);
            AcademicStatusHistory::firstOrCreate(['student_profile_id' => (string) $profile->getKey(), 'to_status' => $status->value], ['from_status' => null, 'reason' => $reason, 'changed_by' => null, 'changed_at' => now()]);
            CommunicationPreference::firstOrCreate(['user_id' => (string) $user->getKey()], ['email' => false, 'push' => false, 'sms' => false]);
        }
    }
}
