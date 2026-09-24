<?php

use App\Actions\Students\UpsertStudentProfile;
use App\Models\AcademicProgram;
use App\Models\AcademicStatusHistory;
use App\Models\Campus;
use App\Models\EventOutbox;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->artisan('migrate')->assertSuccessful();
    Storage::fake('public');
    $this->photoAdmin = User::factory()->create();
    $this->photoAdmin->assignRole(Role::ADMIN);
    $this->photoCampus = Campus::create(['code' => 'CEN', 'name' => 'Central', 'is_active' => true]);
    $this->photoProgram = AcademicProgram::create([
        'campus_id' => (string) $this->photoCampus->getKey(),
        'code' => 'ISC',
        'name' => 'Sistemas',
        'is_active' => true,
    ]);
});

function photoStudentData($test, array $changes = []): array
{
    return array_merge([
        'name' => 'Estudiante Foto',
        'email' => 'foto@example.com',
        'enrollment_number' => 'PHOTO-001',
        'campus_id' => (string) $test->photoCampus->getKey(),
        'academic_program_id' => (string) $test->photoProgram->getKey(),
        'current_semester' => 2,
        'academic_status' => 'active',
        'preferred_contact_channel' => 'institutional_email',
        'locale' => 'es-MX',
    ], $changes);
}

function createStudentWithPhoto($test): array
{
    $test->actingAs($test->photoAdmin)->post('/students', photoStudentData($test, [
        'photo' => UploadedFile::fake()->image('old.jpg'),
    ]))->assertSessionHasNoErrors()->assertRedirect(route('students.index'));

    $student = User::where('email', 'foto@example.com')->firstOrFail();

    return [$student, StudentProfile::where('user_id', (string) $student->getKey())->firstOrFail()];
}

it('keeps a newly uploaded photo when the student create commits', function () {
    [$student, $profile] = createStudentWithPhoto($this);

    expect($profile->photo_path)->toStartWith('student-photos/')
        ->and($student->hasRole(Role::ESTUDIANTE))->toBeTrue();
    Storage::disk('public')->assertExists($profile->photo_path);
});

it('removes the new photo and rolls back the student when initial history creation fails', function () {
    AcademicStatusHistory::creating(function (): void {
        throw new RuntimeException('Deliberate history failure');
    });

    try {
        $failed = false;
        try {
            app(UpsertStudentProfile::class)->execute(photoStudentData($this, [
                'photo' => UploadedFile::fake()->image('new.jpg'),
            ]), null, $this->photoAdmin);
        } catch (RuntimeException $exception) {
            $failed = $exception->getMessage() === 'Deliberate history failure';
        }

        expect($failed)->toBeTrue()
            ->and(User::where('email', 'foto@example.com')->exists())->toBeFalse()
            ->and(StudentProfile::count())->toBe(0)
            ->and(AcademicStatusHistory::count())->toBe(0)
            ->and(EventOutbox::count())->toBe(0)
            ->and(User::all()->filter(fn (User $user) => $user->hasRole(Role::ESTUDIANTE)))->toHaveCount(0);
        Storage::disk('public')->assertDirectoryEmpty('student-photos');
    } finally {
        AcademicStatusHistory::flushEventListeners();
    }
});

it('retains the previous photo when an update does not upload another', function () {
    [$student, $profile] = createStudentWithPhoto($this);
    $oldPath = $profile->photo_path;

    $this->actingAs($this->photoAdmin)->patch('/students/'.$student->getKey(), photoStudentData($this, [
        'name' => 'Nombre actualizado',
    ]))->assertSessionHasNoErrors();

    expect($student->fresh()->name)->toBe('Nombre actualizado')
        ->and($profile->fresh()->photo_path)->toBe($oldPath);
    Storage::disk('public')->assertExists($oldPath);
});

it('retains the previous photo when an update without a new photo fails', function () {
    [$student, $profile] = createStudentWithPhoto($this);
    $oldPath = $profile->photo_path;
    StudentProfile::saving(function (): void {
        throw new RuntimeException('Deliberate profile failure');
    });

    try {
        $failed = false;
        try {
            app(UpsertStudentProfile::class)->execute(photoStudentData($this, [
                'name' => 'Nombre fallido',
            ]), $student, $this->photoAdmin);
        } catch (RuntimeException $exception) {
            $failed = $exception->getMessage() === 'Deliberate profile failure';
        }

        expect($failed)->toBeTrue()
            ->and($student->fresh()->name)->toBe('Estudiante Foto')
            ->and($profile->fresh()->photo_path)->toBe($oldPath);
        Storage::disk('public')->assertExists($oldPath);
    } finally {
        StudentProfile::flushEventListeners();
    }
});

it('replaces the previous photo only after the database commits the new path', function () {
    [$student, $profile] = createStudentWithPhoto($this);
    $oldPath = $profile->photo_path;

    $this->actingAs($this->photoAdmin)->patch('/students/'.$student->getKey(), photoStudentData($this, [
        'photo' => UploadedFile::fake()->image('new.jpg'),
    ]))->assertSessionHasNoErrors();

    $newPath = $profile->fresh()->photo_path;
    expect($newPath)->not->toBe($oldPath)->toStartWith('student-photos/');
    Storage::disk('public')->assertExists($newPath);
    Storage::disk('public')->assertMissing($oldPath);
});

it('removes the replacement photo and preserves the old photo if Mongo persistence fails', function () {
    [$student, $profile] = createStudentWithPhoto($this);
    $oldPath = $profile->photo_path;
    StudentProfile::saving(function (): void {
        throw new RuntimeException('Deliberate profile failure');
    });

    try {
        $failed = false;
        try {
            app(UpsertStudentProfile::class)->execute(photoStudentData($this, [
                'photo' => UploadedFile::fake()->image('new.jpg'),
            ]), $student, $this->photoAdmin);
        } catch (RuntimeException $exception) {
            $failed = $exception->getMessage() === 'Deliberate profile failure';
        }

        expect($failed)->toBeTrue()
            ->and($profile->fresh()->photo_path)->toBe($oldPath)
            ->and(Storage::disk('public')->allFiles('student-photos'))->toBe([$oldPath]);
        Storage::disk('public')->assertExists($oldPath);
    } finally {
        StudentProfile::flushEventListeners();
    }
});

it('does not allow a request photo_path to delete an unrelated file', function () {
    [$student, $profile] = createStudentWithPhoto($this);
    $oldPath = $profile->photo_path;
    Storage::disk('public')->put('unrelated.txt', 'keep');

    $this->actingAs($this->photoAdmin)->patch('/students/'.$student->getKey(), photoStudentData($this, [
        'photo_path' => '../../unrelated.txt',
    ]))->assertSessionHasNoErrors();

    expect($profile->fresh()->photo_path)->toBe($oldPath);
    Storage::disk('public')->assertExists($oldPath);
    Storage::disk('public')->assertExists('unrelated.txt');
});

it('rolls back the new photo and database writes when the outbox write fails', function () {
    EventOutbox::creating(function (): void {
        throw new RuntimeException('Deliberate outbox failure');
    });

    try {
        $failed = false;
        try {
            app(UpsertStudentProfile::class)->execute(photoStudentData($this, [
                'photo' => UploadedFile::fake()->image('new.jpg'),
            ]), null, $this->photoAdmin);
        } catch (RuntimeException $exception) {
            $failed = $exception->getMessage() === 'Deliberate outbox failure';
        }

        expect($failed)->toBeTrue()
            ->and(User::where('email', 'foto@example.com')->exists())->toBeFalse()
            ->and(StudentProfile::count())->toBe(0)
            ->and(AcademicStatusHistory::count())->toBe(0)
            ->and(EventOutbox::count())->toBe(0);
        Storage::disk('public')->assertDirectoryEmpty('student-photos');
    } finally {
        EventOutbox::flushEventListeners();
    }
});
