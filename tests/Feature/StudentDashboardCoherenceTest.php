<?php

use App\Actions\Students\UpsertStudentProfile;
use App\Models\AcademicProgram;
use App\Models\Campus;
use App\Models\Role;
use App\Models\SecurityEvent;
use App\Models\StudentProfile;
use App\Models\User;

/*
 * Rúbrica 10.2/10.5 (E1): los indicadores de /students y el reporte CSV deben
 * coincidir con la base. Se construye un conjunto conocido y se compara contra
 * consultas independientes a la colección, sin pasar por el controlador.
 */

beforeEach(function () {
    $this->artisan('migrate')->assertSuccessful();
});

function dashboardCoherenceDataset(): array
{
    $campus = Campus::create(['code' => 'CEN', 'name' => 'Campus Central', 'is_active' => true]);
    $other = Campus::create(['code' => 'NOR', 'name' => 'Campus Norte', 'is_active' => true]);
    $program = AcademicProgram::create(['campus_id' => (string) $campus->getKey(), 'code' => 'ISC', 'name' => 'Ingeniería de Sistemas', 'is_active' => true]);
    $upsert = app(UpsertStudentProfile::class);

    $make = function (string $suffix, string $status, Campus $at, ?string $phone, ?string $photo, bool $old = false) use ($upsert, $program) {
        $user = $upsert->execute([
            'name' => "Alumno {$suffix}",
            'email' => "alumno.{$suffix}@example.com",
            'enrollment_number' => "COH-{$suffix}",
            'campus_id' => (string) $at->getKey(),
            'academic_program_id' => (string) $program->getKey(),
            'current_semester' => 3,
            'academic_status' => $status,
            'preferred_contact_channel' => 'institutional_email',
            'locale' => 'es-MX',
        ]);
        $profile = StudentProfile::where('user_id', (string) $user->getKey())->firstOrFail();
        $profile->phone = $phone;
        $profile->photo_path = $photo;
        if ($old) {
            $profile->timestamps = false;
            $profile->updated_at = now()->subDays(10);
        }
        $profile->save();

        return $user;
    };

    $make('A', 'active', $campus, '5550000001', 'students/a.jpg');            // completo, reciente
    $make('B', 'active', $campus, null, 'students/b.jpg');                    // incompleto (sin teléfono)
    $make('C', 'suspended', $other, '5550000003', 'students/c.jpg', true);    // completo, antiguo
    $make('D', 'inactive', $campus, '5550000004', null);                      // incompleto (sin foto)
    $orphan = $make('E', 'active', $campus, null, null);                      // sin cuenta: no operativo
    User::query()->whereKey($orphan->getKey())->delete();

    return [$campus, $other];
}

function independentStudentCounts(array $filter = []): array
{
    $userIds = User::query()->pluck('id')->map(fn ($id) => (string) $id)->all();
    $base = fn () => StudentProfile::query()->whereIn('user_id', $userIds)
        ->when($filter['status'] ?? null, fn ($q, $s) => $q->where('academic_status', $s))
        ->when($filter['campus'] ?? null, fn ($q, $c) => $q->where('campus_id', $c));

    return [
        'total' => $base()->count(),
        'active' => $base()->where('academic_status', 'active')->count(),
        'incomplete' => $base()->where(fn ($q) => $q->whereNull('photo_path')->orWhereNull('phone'))->count(),
        'recent' => $base()->where('updated_at', '>=', now()->subDays(7))->count(),
        'enrollments' => $base()->pluck('enrollment_number')->sort()->values()->all(),
    ];
}

function reportRows(string $csv): array
{
    $lines = array_values(array_filter(preg_split('/\r?\n/', trim($csv))));
    $header = str_getcsv(array_shift($lines), ',', '"', '');

    return array_map(fn ($line) => array_combine($header, str_getcsv($line, ',', '"', '')), $lines);
}

function coherenceAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN);

    return withConfirmedTestTwoFactor($admin);
}

it('shows /students indicators that match independent database counts for a known dataset', function () {
    dashboardCoherenceDataset();
    $admin = coherenceAdmin();
    $db = independentStudentCounts();

    expect($db)->toMatchArray(['total' => 4, 'active' => 2, 'incomplete' => 2, 'recent' => 3]);

    $this->actingAs($admin)->get('/students')->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('statistics.total', $db['total'])
            ->where('statistics.active', $db['active'])
            ->where('statistics.incomplete', $db['incomplete'])
            ->where('statistics.recent', $db['recent'])
            ->where('students.total', $db['total']));
});

it('exports a CSV report whose rows match the database for each filter', function () {
    [$campus, $other] = dashboardCoherenceDataset();
    $admin = coherenceAdmin();

    foreach ([[], ['status' => 'active'], ['campus' => (string) $other->getKey()], ['status' => 'inactive', 'campus' => (string) $campus->getKey()]] as $filter) {
        $db = independentStudentCounts($filter);
        $response = $this->actingAs($admin)->get('/students/report.csv?'.http_build_query($filter))->assertOk();
        expect($response->headers->get('Content-Type'))->toContain('text/csv');
        $rows = reportRows($response->streamedContent());

        expect(count($rows))->toBe($db['total'])
            ->and(collect($rows)->pluck('Matrícula')->sort()->values()->all())->toBe($db['enrollments']);
        if ($status = $filter['status'] ?? null) {
            expect(collect($rows)->pluck('Estatus')->unique()->values()->all())->toBe([$status]);
        }

        $this->actingAs($admin)->get('/students?'.http_build_query($filter))->assertOk()
            ->assertInertia(fn ($page) => $page->where('students.total', $db['total']));
    }

    expect(SecurityEvent::where('type', 'student_report_export')->count())->toBe(4);
});

it('rejects an invalid report filter without exporting or auditing', function () {
    dashboardCoherenceDataset();
    $admin = coherenceAdmin();

    $this->actingAs($admin)->get('/students/report.csv?status=desconocido')->assertSessionHasErrors('status');
    $this->actingAs($admin)->get('/students/report.csv?campus=no-es-un-id')->assertSessionHasErrors('campus');

    expect(SecurityEvent::where('type', 'student_report_export')->count())->toBe(0);
});
