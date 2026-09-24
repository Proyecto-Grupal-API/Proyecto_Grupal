<?php

use App\Actions\Students\UpsertStudentProfile;
use App\Models\AcademicProgram;
use App\Models\AcademicStatusHistory;
use App\Models\Campus;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // TestCase clears the MongoDB test database; run the real migrations here
    // so these tests exercise the production unique indexes and transactions.
    $this->artisan('migrate')->assertSuccessful();
});

function studentManagementData(array $overrides = []): array
{
    $campus = Campus::create(['code' => 'CEN', 'name' => 'Campus Central', 'is_active' => true]);
    $program = AcademicProgram::create([
        'campus_id' => (string) $campus->getKey(),
        'code' => 'ISC',
        'name' => 'Ingeniería de Sistemas',
        'is_active' => true,
    ]);

    return array_merge([
        'name' => 'Estudiante Ejemplo',
        'email' => ' Student@Example.COM ',
        'enrollment_number' => ' abc-123 ',
        'campus_id' => (string) $campus->getKey(),
        'academic_program_id' => (string) $program->getKey(),
        'current_semester' => 2,
        'academic_status' => 'active',
        'personal_email' => ' Personal@Example.COM ',
        'preferred_contact_channel' => 'institutional_email',
        'locale' => 'es-MX',
    ], $overrides);
}

it('creates an administrative student with normalized identifiers, initial history, and one global student role', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN);

    $this->actingAs($admin)->post('/students', studentManagementData())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('students.index'));

    $student = User::where('email', 'student@example.com')->firstOrFail();
    $profile = StudentProfile::where('user_id', (string) $student->getKey())->firstOrFail();
    $history = AcademicStatusHistory::where('student_profile_id', (string) $profile->getKey())->firstOrFail();

    expect($student->account_activation_pending)->toBeTrue()
        ->and($student->password)->toBeNull()
        ->and($profile->enrollment_number)->toBe('ABC-123')
        ->and($profile->personal_email)->toBe('personal@example.com')
        ->and($history->from_status)->toBeNull()
        ->and($history->to_status->value)->toBe('active')
        ->and((string) $history->changed_by)->toBe((string) $admin->getKey())
        ->and($history->reason)->not->toBeEmpty()
        ->and($history->changed_at)->not->toBeNull()
        ->and($student->fresh()->hasRole(Role::ESTUDIANTE))->toBeTrue();

    $studentRoles = array_values(array_filter($student->fresh()->roles, fn (array $role) =>
        $role['name'] === Role::ESTUDIANTE
        && ($role['scope_type'] ?? null) === null
        && ($role['scope_id'] ?? null) === null
    ));
    expect($studentRoles)->toHaveCount(1);
});

it('does not duplicate a global student role already present on a new user', function () {
    $student = new User;
    $student->roles = [[
        'name' => Role::ESTUDIANTE,
        'scope_type' => null,
        'scope_id' => null,
        'assigned_at' => now()->toDateTimeString(),
    ]];

    $created = app(UpsertStudentProfile::class)->execute(studentManagementData(), $student);

    expect($created->hasRole(Role::ESTUDIANTE))->toBeTrue()
        ->and(array_filter($created->roles, fn (array $role) => $role['name'] === Role::ESTUDIANTE))->toHaveCount(1);
});

it('rolls back a newly created user when the profile violates a unique enrollment index', function () {
    StudentProfile::create([
        'user_id' => (string) User::factory()->create()->getKey(),
        'enrollment_number' => 'ABC-123',
        'academic_status' => 'active',
    ]);

    $failed = false;
    try {
        app(UpsertStudentProfile::class)->execute(studentManagementData());
    } catch (\Throwable) {
        $failed = true;
    }

    expect($failed)->toBeTrue()
        ->and(User::where('email', 'student@example.com')->exists())->toBeFalse()
        ->and(StudentProfile::where('enrollment_number', 'ABC-123')->count())->toBe(1);
});

it('rolls back a new user and profile when initial history creation fails', function () {
    AcademicStatusHistory::creating(function (): void {
        throw new RuntimeException('Deliberate initial history failure');
    });

    try {
        $failed = false;
        try {
            app(UpsertStudentProfile::class)->execute(studentManagementData());
        } catch (RuntimeException $exception) {
            $failed = $exception->getMessage() === 'Deliberate initial history failure';
        }

        expect($failed)->toBeTrue()
            ->and(User::where('email', 'student@example.com')->exists())->toBeFalse()
            ->and(StudentProfile::where('enrollment_number', 'ABC-123')->exists())->toBeFalse()
            ->and(AcademicStatusHistory::count())->toBe(0);
    } finally {
        AcademicStatusHistory::flushEventListeners();
    }
});

it('creates the critical unique indexes through migrations without seeding', function () {
    $indexes = iterator_to_array(DB::connection('mongodb')->getCollection('student_profiles')->listIndexes());
    $byName = [];
    foreach ($indexes as $index) {
        $byName[$index->getName()] = $index;
    }

    expect($byName['user_id_1']->isUnique())->toBeTrue()
        ->and($byName['enrollment_number_1']->isUnique())->toBeTrue();
});

it('requires the selected contact detail when editing a student', function (string $channel, string $field, string $value, bool $valid) {
    $manager = User::factory()->create();
    $manager->assignRole(Role::ADMIN);
    $data = studentManagementData();
    $student = app(UpsertStudentProfile::class)->execute($data, null, $manager);
    $original = $student->studentProfile->fresh();

    $response = $this->actingAs($manager)->patch('/students/'.$student->getKey(), array_replace($data, [
        'preferred_contact_channel' => $channel,
        $field => $value,
    ]));

    if ($valid) {
        $response->assertSessionHasNoErrors()->assertRedirect(route('students.index'));
        expect($original->fresh()->preferred_contact_channel->value)->toBe($channel)
            ->and($original->fresh()->{$field})->toBe($value);
    } else {
        $response->assertSessionHasErrors($field);
        expect($original->fresh()->preferred_contact_channel->value)->toBe('institutional_email')
            ->and($original->fresh()->{$field})->toBe($original->{$field});
    }
})->with([
    'personal email present' => ['personal_email', 'personal_email', 'contact@example.com', true],
    'personal email absent' => ['personal_email', 'personal_email', '', false],
    'phone present' => ['phone', 'phone', '5551234567', true],
    'phone absent' => ['phone', 'phone', '', false],
]);

it('denies a normal student every administrative student route without changing data', function () {
    $data = studentManagementData();
    $target = app(UpsertStudentProfile::class)->execute($data);
    $student = User::factory()->create();
    $student->assignRole(Role::ESTUDIANTE);
    $counts = [User::count(), StudentProfile::count(), AcademicStatusHistory::count()];

    $this->actingAs($student)->get('/students')->assertForbidden();
    $this->actingAs($student)->get('/students/create')->assertForbidden();
    $this->actingAs($student)->post('/students', $data)->assertForbidden();
    $this->actingAs($student)->get('/students/'.$target->getKey().'/edit')->assertForbidden();
    $this->actingAs($student)->patch('/students/'.$target->getKey(), array_replace($data, ['name' => 'Nombre manipulado']))->assertForbidden();
    $this->actingAs($student)->post('/students/import', [
        'file' => UploadedFile::fake()->createWithContent('students.csv', "matricula,nombre,correo_institucional,campus,carrera,semestre,grupo,estatus,correo_personal,telefono,canal_preferido\nB-002,Otro,b@example.com,CEN,ISC,2,A,active,,,institutional_email\n"),
    ])->assertForbidden();

    expect([User::count(), StudentProfile::count(), AcademicStatusHistory::count()])->toBe($counts)
        ->and($target->fresh()->name)->toBe('Estudiante Ejemplo');
});

it('allows each student management role through the administrative HTTP routes', function (string $role) {
    $data = studentManagementData();
    $target = app(UpsertStudentProfile::class)->execute($data);
    $manager = User::factory()->create();
    $manager->assignRole($role);

    $this->actingAs($manager)->get('/students')->assertOk();
    $this->actingAs($manager)->get('/students/create')->assertOk();
    $this->actingAs($manager)->get('/students/'.$target->getKey().'/edit')->assertOk();
    $this->actingAs($manager)->post('/students/import', [
        'file' => UploadedFile::fake()->createWithContent('students.csv', "matricula,nombre,correo_institucional,campus,carrera,semestre,grupo,estatus,correo_personal,telefono,canal_preferido\nB-002,Otro,b@example.com,CEN,ISC,2,A,active,,,institutional_email\n"),
    ])->assertSessionHasNoErrors()->assertSessionHas('success', 'Importación completada: 1 altas y 0 actualizaciones.');
    expect(StudentProfile::where('enrollment_number', 'B-002')->exists())->toBeTrue();
})->with([
    'admin' => [Role::ADMIN],
    'maestro' => [Role::MAESTRO],
    'student_manager' => [Role::STUDENT_MANAGER],
]);
