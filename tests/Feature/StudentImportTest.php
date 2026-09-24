<?php

use App\Actions\Students\ImportStudents;
use App\Models\AcademicProgram;
use App\Models\AcademicStatusHistory;
use App\Models\Campus;
use App\Models\EventOutbox;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->artisan('migrate')->assertSuccessful();
    $this->importAdmin = User::factory()->create();
    $this->importAdmin->assignRole(Role::ADMIN);
    $this->importCampus = Campus::create(['code' => 'CEN', 'name' => 'Central', 'is_active' => true]);
    $this->importProgram = AcademicProgram::create([
        'campus_id' => (string) $this->importCampus->getKey(),
        'code' => 'ISC',
        'name' => 'Sistemas',
        'is_active' => true,
    ]);
});

function importStudentRow(array $changes = []): array
{
    return array_replace([
        'matricula' => 'A-001',
        'nombre' => 'Estudiante A',
        'correo_institucional' => 'a@example.com',
        'campus' => 'CEN',
        'carrera' => 'ISC',
        'semestre' => '2',
        'grupo' => 'A',
        'estatus' => 'active',
        'correo_personal' => '',
        'telefono' => '',
        'canal_preferido' => 'institutional_email',
    ], $changes);
}

function importStudentCsv(array $rows): UploadedFile
{
    $headers = array_keys(importStudentRow());
    $lines = [implode(',', $headers)];
    foreach ($rows as $row) {
        $lines[] = implode(',', array_values(importStudentRow($row)));
    }

    return UploadedFile::fake()->createWithContent('students.csv', implode("\n", $lines)."\n");
}

function postStudentImport($test, array $rows)
{
    return $test->actingAs($test->importAdmin)->post('/students/import', [
        'file' => importStudentCsv($rows),
    ]);
}

it('imports multiple normalized students with profiles, initial histories, global roles, pending activation, and outbox events', function () {
    postStudentImport($this, [
        importStudentRow([
            'matricula' => ' a-001 ',
            'correo_institucional' => ' A@Example.COM ',
            'correo_personal' => ' PersonalA@Example.COM ',
        ]),
        importStudentRow([
            'matricula' => ' b-002 ',
            'nombre' => 'Estudiante B',
            'correo_institucional' => ' B@Example.COM ',
            'estatus' => 'graduated',
        ]),
    ])->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Importación completada: 2 altas y 0 actualizaciones.');

    foreach (['a@example.com' => ['A-001', 'active'], 'b@example.com' => ['B-002', 'graduated']] as $email => [$enrollment, $status]) {
        $student = User::where('email', $email)->firstOrFail();
        $profile = StudentProfile::where('user_id', (string) $student->getKey())->firstOrFail();
        $history = AcademicStatusHistory::where('student_profile_id', (string) $profile->getKey())->firstOrFail();
        expect($student->password)->toBeNull()
            ->and($student->account_activation_pending)->toBeTrue()
            ->and($student->email_verified_at)->toBeNull()
            ->and($student->hasRole(Role::ESTUDIANTE))->toBeTrue()
            ->and($profile->enrollment_number)->toBe($enrollment)
            ->and($profile->academic_status->value)->toBe($status)
            ->and($history->from_status)->toBeNull()
            ->and($history->to_status->value)->toBe($status)
            ->and((string) $history->changed_by)->toBe((string) $this->importAdmin->getKey());
        $studentRoles = array_filter($student->roles, fn (array $role) =>
            $role['name'] === Role::ESTUDIANTE && $role['scope_type'] === null && $role['scope_id'] === null
        );
        expect($studentRoles)->toHaveCount(1);
    }
    expect(StudentProfile::where('enrollment_number', 'A-001')->firstOrFail()->personal_email)->toBe('personala@example.com')
        ->and(EventOutbox::where('event_name', 'student.profile.changed.v1')->count())->toBe(2);
});

it('rejects a duplicate enrollment after normalization before persisting any row', function () {
    postStudentImport($this, [
        importStudentRow(['matricula' => 'abc123']),
        importStudentRow(['matricula' => ' ABC123 ', 'correo_institucional' => 'b@example.com']),
    ])->assertSessionHasErrors('file');

    expect(implode(' ', session('errors')->get('file')))->toContain('Fila 3, enrollment_number')
        ->and(User::where('email', 'a@example.com')->exists())->toBeFalse()
        ->and(StudentProfile::count())->toBe(0);
});

it('rejects a duplicate institutional email after normalization before persisting any row', function () {
    postStudentImport($this, [
        importStudentRow(['correo_institucional' => 'Alumno@ITC.mx']),
        importStudentRow(['matricula' => 'B-002', 'correo_institucional' => ' alumno@itc.mx ']),
    ])->assertSessionHasErrors('file');

    expect(implode(' ', session('errors')->get('file')))->toContain('Fila 3, email')
        ->and(StudentProfile::count())->toBe(0)
        ->and(User::count())->toBe(1);
});

it('exposes every CSV row error to the administrative Inertia page', function () {
    $response = $this->actingAs($this->importAdmin)->from('/students')->followingRedirects()->post('/students/import', [
        'file' => importStudentCsv([
            importStudentRow(),
            importStudentRow(),
        ]),
    ]);

    $response->assertInertia(fn ($page) => $page
        ->component('Students/Index')
        ->where('canImportStudents', true)
        ->has('importErrors', 2));
    expect(StudentProfile::count())->toBe(0);
});

it('rejects an invalid later row before persisting an earlier valid row', function () {
    postStudentImport($this, [
        importStudentRow(),
        importStudentRow(['matricula' => 'B-002', 'correo_institucional' => 'b@example.com', 'semestre' => '99']),
    ])->assertSessionHasErrors('file');

    expect(User::where('email', 'a@example.com')->exists())->toBeFalse()
        ->and(StudentProfile::count())->toBe(0)
        ->and(EventOutbox::count())->toBe(0);
});

it('rejects a program belonging to another campus', function () {
    $otherCampus = Campus::create(['code' => 'SUR', 'name' => 'Sur', 'is_active' => true]);
    AcademicProgram::create(['campus_id' => (string) $otherCampus->getKey(), 'code' => 'MED', 'name' => 'Medicina']);

    postStudentImport($this, [importStudentRow(['carrera' => 'MED'])])->assertSessionHasErrors('file');

    expect(implode(' ', session('errors')->get('file')))->toContain('academic_program_id')
        ->and(StudentProfile::count())->toBe(0);
});

it('requires a personal email when that contact channel is selected', function () {
    postStudentImport($this, [importStudentRow(['canal_preferido' => 'personal_email'])])
        ->assertSessionHasErrors('file');

    expect(implode(' ', session('errors')->get('file')))->toContain('personal_email')
        ->and(StudentProfile::count())->toBe(0);
});

it('requires a phone when that contact channel is selected', function () {
    postStudentImport($this, [importStudentRow(['canal_preferido' => 'phone'])])
        ->assertSessionHasErrors('file');

    expect(implode(' ', session('errors')->get('file')))->toContain('phone')
        ->and(StudentProfile::count())->toBe(0);
});

it('does not merge the enrollment of one student with the email of another', function () {
    $first = User::factory()->create(['email' => 'a@example.com']);
    StudentProfile::create(['user_id' => (string) $first->getKey(), 'enrollment_number' => 'A-001', 'academic_status' => 'active']);
    $second = User::factory()->create(['email' => 'b@example.com']);

    postStudentImport($this, [importStudentRow(['correo_institucional' => 'b@example.com'])])
        ->assertSessionHasErrors('file');

    expect($first->fresh()->email)->toBe('a@example.com')
        ->and($second->fresh()->email)->toBe('b@example.com')
        ->and(StudentProfile::count())->toBe(1);
});

it('rejects a new enrollment when its email belongs to an existing user despite different casing', function () {
    User::factory()->create(['email' => 'Alumno@ITC.mx']);

    postStudentImport($this, [importStudentRow(['correo_institucional' => ' alumno@itc.mx '])])
        ->assertSessionHasErrors('file');

    expect(implode(' ', session('errors')->get('file')))->toContain('Fila 2, email')
        ->and(StudentProfile::count())->toBe(0)
        ->and(User::count())->toBe(2);
});

it('updates a matched enrollment when its new email is not owned by another user', function () {
    $existing = User::factory()->create(['email' => 'old@example.com']);
    StudentProfile::create(['user_id' => (string) $existing->getKey(), 'enrollment_number' => 'A-001', 'academic_status' => 'active']);

    postStudentImport($this, [importStudentRow(['correo_institucional' => 'new@example.com'])])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Importación completada: 0 altas y 1 actualizaciones.');

    expect($existing->fresh()->email)->toBe('new@example.com')
        ->and(StudentProfile::count())->toBe(1)
        ->and(User::count())->toBe(2);
});

it('rolls back every student, history, role, and outbox entry if a later row fails to persist', function () {
    $createdHistories = 0;
    AcademicStatusHistory::creating(function () use (&$createdHistories): void {
        if (++$createdHistories === 2) {
            throw new RuntimeException('Deliberate failure in the second row');
        }
    });

    try {
        $failed = false;
        try {
            app(ImportStudents::class)->execute(importStudentCsv([
                importStudentRow(),
                importStudentRow(['matricula' => 'B-002', 'correo_institucional' => 'b@example.com']),
            ]), $this->importAdmin);
        } catch (RuntimeException $exception) {
            $failed = $exception->getMessage() === 'Deliberate failure in the second row';
        }

        expect($failed)->toBeTrue()
            ->and(User::whereIn('email', ['a@example.com', 'b@example.com'])->count())->toBe(0)
            ->and(StudentProfile::count())->toBe(0)
            ->and(AcademicStatusHistory::count())->toBe(0)
            ->and(EventOutbox::count())->toBe(0)
            ->and(User::all()->filter(fn (User $user) => $user->hasRole(Role::ESTUDIANTE)))->toHaveCount(0);
    } finally {
        AcademicStatusHistory::flushEventListeners();
    }
});
