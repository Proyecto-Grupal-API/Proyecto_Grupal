<?php

namespace App\Http\Controllers;

use App\Enums\PreferredContactChannel;
use App\Enums\StudentStatus;
use App\Models\Campus;
use App\Models\StudentProfile;
use App\Http\Requests\StoreStudentRequest;
use App\Http\Requests\UpdateStudentRequest;
use App\Actions\Students\UpsertStudentProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;

class StudentController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', StudentProfile::class);
        $profiles = StudentProfile::with(['user', 'campus', 'academicProgram'])->get();
        $search = trim((string) $request->input('search'));
        $status = $request->input('status');
        $campus = $request->input('campus');
        $profiles = $profiles->filter(function (StudentProfile $profile) use ($search, $status, $campus): bool {
            $matchesSearch = ! $search || str_contains(strtolower((string) $profile->user?->name), strtolower($search)) || str_contains(strtolower((string) $profile->user?->email), strtolower($search)) || str_contains(strtolower((string) $profile->enrollment_number), strtolower($search));
            return $matchesSearch && (! $status || $profile->academic_status?->value === $status) && (! $campus || (string) $profile->campus_id === (string) $campus);
        })->values();
        $page = LengthAwarePaginator::resolveCurrentPage();
        $students = new LengthAwarePaginator($profiles->forPage($page, 10)->values(), $profiles->count(), 10, $page, ['path' => url('/students')]);
        $students->appends($request->only(['search', 'status', 'campus']));

        return Inertia::render('Students/Index', [
            'students' => $students,
            'filters' => $request->only(['search', 'status', 'campus']),
            'canImportStudents' => $request->user()->can('create', StudentProfile::class),
            'importErrors' => $request->session()->get('errors')?->getBag('default')->get('file') ?? [],
            'campuses' => Campus::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'statuses' => StudentStatus::options(),
            'statistics' => [
                'total' => StudentProfile::count(),
                'active' => StudentProfile::where('academic_status', StudentStatus::Active->value)->count(),
                'incomplete' => StudentProfile::whereNull('photo_path')->orWhereNull('phone')->count(),
                'recent' => StudentProfile::where('updated_at', '>=', now()->subDays(7))->count(),
            ],
        ]);
    }

    public function create()
    {
        $this->authorize('create', StudentProfile::class);
        return Inertia::render('Students/Create', $this->formOptions());
    }

    public function store(StoreStudentRequest $request, UpsertStudentProfile $upsert)
    {
        $upsert->execute($request->validated(), null, $request->user());
        return to_route('students.index')->with('success', 'La cuenta del estudiante se creó correctamente.');
    }

    public function edit(\App\Models\User $student)
    {
        $this->authorize('update', $student->studentProfile);
        return Inertia::render('Students/Edit', array_merge($this->formOptions(), ['student' => $student->load(['studentProfile.statusHistory.changedBy'])]));
    }

    public function update(UpdateStudentRequest $request, \App\Models\User $student, UpsertStudentProfile $upsert)
    {
        $upsert->execute($request->validated(), $student, $request->user());
        return to_route('students.index')->with('success', 'El perfil se actualizó correctamente.');
    }

    private function formOptions(): array
    {
        return [
            'campuses' => Campus::with('academicPrograms')->where('is_active', true)->orderBy('name')->get(),
            'statuses' => StudentStatus::options(),
            'contactChannels' => PreferredContactChannel::options(),
        ];
    }
}
