<?php

namespace App\Http\Controllers;

use App\Actions\Students\ChangeStudentAcademicStatus;
use App\Enums\ConsentType;
use App\Events\StudentConsentChanged;
use App\Events\StudentProfileChanged;
use App\Models\CommunicationPreference;
use App\Models\Consent;
use App\Models\StudentProfile;
use App\Services\StudentStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StudentServicesController extends Controller
{
    public function index(Request $request): Response
    {
        $profile = $this->profileForUser($request->user());

        return Inertia::render('StudentServices/Index', [
            'student' => app(StudentStatusService::class)->forUserId((string) $profile->user_id, true),
            'consents' => $this->consentItems($profile),
            'preferences' => $this->preferenceValues($profile),
        ]);
    }

    public function status(Request $request, string $studentId): JsonResponse
    {
        return $this->success(app(StudentStatusService::class)->forUserId($studentId));
    }

    public function statusHistory(Request $request, string $studentId): JsonResponse
    {
        $status = app(StudentStatusService::class)->forUserId($studentId, true);
        return $this->success(['student_id' => $status['student_id'], 'items' => $status['history']]);
    }

    public function consents(Request $request, string $studentId): JsonResponse
    {
        $profile = $this->profile($studentId);
        $this->authorizeServiceView($request, $profile);
        return $this->success(['student_id' => (string) $profile->getKey(), 'items' => $this->consentItems($profile)]);
    }

    public function acceptConsent(Request $request, string $studentId): JsonResponse
    {
        $profile = $this->profile($studentId);
        return $this->accept($request, $profile);
    }

    public function revokeConsent(Request $request, string $studentId, string $consentId): JsonResponse
    {
        return $this->revoke($request, $this->profile($studentId), $consentId);
    }

    public function preferences(Request $request, string $studentId): JsonResponse
    {
        $profile = $this->profile($studentId);
        $this->authorizeServiceView($request, $profile);
        return $this->success(['student_id' => (string) $profile->getKey(), 'preferences' => $this->preferenceValues($profile)]);
    }

    public function updatePreferences(Request $request, string $studentId): JsonResponse
    {
        return $this->updatePreference($request, $this->profile($studentId));
    }

    public function acceptOwnConsent(Request $request): JsonResponse
    {
        return $this->accept($request, $this->profileForUser($request->user()));
    }

    public function revokeOwnConsent(Request $request, string $consentId): JsonResponse
    {
        return $this->revoke($request, $this->profileForUser($request->user()), $consentId);
    }

    public function updateOwnPreferences(Request $request): JsonResponse
    {
        return $this->updatePreference($request, $this->profileForUser($request->user()));
    }

    public function changeStatus(Request $request, string $studentId, ChangeStudentAcademicStatus $action): JsonResponse
    {
        $profile = $this->profile($studentId);
        $this->authorize('updateAcademicStatus', $profile);
        $data = $request->validate(['status' => ['required', 'string'], 'reason' => ['required', 'string', 'max:1000']]);
        $profile = $action->execute($profile, $data['status'], $data['reason'], $request->user());
        return $this->success(app(StudentStatusService::class)->forUserId((string) $profile->user_id, true));
    }

    private function accept(Request $request, StudentProfile $profile): JsonResponse
    {
        abort_unless($request->user(), 403);
        $this->authorize('viewServices', $profile);
        $data = $request->validate(['consent_id' => ['required', 'string'], 'consent_version' => ['nullable', 'string', 'max:100']]);
        $type = ConsentType::tryFrom($data['consent_id']);
        abort_unless($type && array_key_exists($type->value, config('student_services.consents')), 422, 'Tipo de consentimiento inválido.');
        if ($type->requiresVersion() && empty($data['consent_version'])) abort(422, 'La versión del consentimiento es obligatoria.');
        $consent = Consent::create(['user_id' => (string) $profile->user_id, 'student_profile_id' => (string) $profile->getKey(), 'type' => $type->value, 'version' => $data['consent_version'] ?? null, 'status' => 'accepted', 'accepted_at' => now(), 'actor_id' => (string) $request->user()->getKey()]);
        StudentConsentChanged::dispatch((string) $profile->user_id, $type->value, 'accepted', (string) ($consent->version ?? ''), (string) $request->user()->getKey());
        return $this->success($this->consentItem($profile, $type), 201);
    }

    private function revoke(Request $request, StudentProfile $profile, string $consentId): JsonResponse
    {
        abort_unless($request->user(), 403);
        $this->authorize('viewServices', $profile);
        $type = ConsentType::tryFrom($consentId);
        abort_unless($type, 422, 'Tipo de consentimiento inválido.');
        $data = $request->validate(['consent_record_id' => ['required', 'string']]);
        $accepted = Consent::where('_id', $data['consent_record_id'])
            ->where('user_id', (string) $profile->user_id)
            ->where('type', $type->value)
            ->where('status', 'accepted')
            ->first();
        abort_unless($accepted, 422, 'No existe el consentimiento aceptado indicado.');
        abort_if(Consent::where('revokes_consent_id', (string) $accepted->getKey())->exists(), 422, 'El consentimiento indicado ya fue revocado.');
        $consent = Consent::create(['user_id' => (string) $profile->user_id, 'student_profile_id' => (string) $profile->getKey(), 'type' => $type->value, 'version' => $accepted->version, 'status' => 'revoked', 'revoked_at' => now(), 'actor_id' => (string) $request->user()->getKey(), 'revokes_consent_id' => (string) $accepted->getKey()]);
        StudentConsentChanged::dispatch((string) $profile->user_id, $type->value, 'revoked', (string) ($consent->version ?? ''), (string) $request->user()->getKey());
        return $this->success($this->consentItem($profile, $type));
    }

    private function updatePreference(Request $request, StudentProfile $profile): JsonResponse
    {
        abort_unless($request->user(), 403);
        $this->authorize('viewServices', $profile);
        $data = $request->validate(['email' => ['sometimes', 'boolean'], 'push' => ['sometimes', 'boolean'], 'sms' => ['sometimes', 'boolean']]);
        abort_if($data === [], 422, 'Indica al menos una preferencia.');
        $preferences = CommunicationPreference::firstOrNew(['user_id' => (string) $profile->user_id]);
        $preferences->fill($data); $preferences->updated_by = (string) $request->user()->getKey(); $preferences->save();
        StudentProfileChanged::dispatch((string) $profile->user_id, 'communication_preferences_changed', array_keys($data), (string) $request->user()->getKey());
        return $this->success(['student_id' => (string) $profile->getKey(), 'preferences' => $this->preferenceValues($profile), 'updated_at' => $preferences->updated_at?->toISOString()]);
    }

    private function profile(string $id): StudentProfile { return StudentProfile::where('_id', $id)->orWhere('user_id', $id)->firstOrFail(); }
    private function profileForUser($user): StudentProfile { return StudentProfile::where('user_id', (string) $user->getKey())->firstOrFail(); }
    private function authorizeServiceView(Request $request, StudentProfile $profile): void { if ($request->user()) $this->authorize('viewServices', $profile); }
    private function preferenceValues(StudentProfile $profile): array { $p = CommunicationPreference::where('user_id', (string) $profile->user_id)->first(); return ['email' => $p?->email ?? false, 'push' => $p?->push ?? false, 'sms' => $p?->sms ?? false]; }
    private function consentItems(StudentProfile $profile): array { return collect(config('student_services.consents'))->map(fn ($definition, $type) => $this->consentItem($profile, ConsentType::from($type), $definition))->values()->all(); }
    private function consentItem(StudentProfile $profile, ConsentType $type, ?array $definition = null): array { $definition ??= config('student_services.consents.'.$type->value); $records = Consent::where('user_id', (string) $profile->user_id)->where('type', $type->value)->latest('created_at')->get(); $active = $records->first(fn ($record) => $record->status === 'accepted' && ! Consent::where('revokes_consent_id', (string) $record->getKey())->exists()); $latest = $records->first(); return ['id' => $type->value, 'name' => $definition['name'], 'description' => $definition['description'], 'version' => $active?->version ?? $latest?->version ?? $definition['version'], 'required' => $definition['required'], 'status' => $active ? 'accepted' : ($latest?->status ?? 'pending'), 'acceptance_id' => $active ? (string) $active->getKey() : null, 'accepted_at' => $active?->accepted_at?->toISOString(), 'revoked_at' => $latest?->revoked_at?->toISOString()]; }

    private function success(array $data, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => [
                'request_id' => request()->header('X-Request-Id', (string) str()->uuid()),
                'api_version' => 'v1',
            ],
        ], $status);
    }
}
