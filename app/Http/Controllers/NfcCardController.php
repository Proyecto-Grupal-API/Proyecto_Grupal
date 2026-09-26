<?php

namespace App\Http\Controllers;

use App\Actions\Nfc\ChangeNfcCardStatus;
use App\Enums\NfcCardStatus;
use App\Events\CredentialChanged;
use App\Models\NfcCard;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\ExecutesMongoAtomically;
use App\Support\NfcUid;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

class NfcCardController extends Controller
{
    use ExecutesMongoAtomically;

    /**
     * Mostrar las tarjetas NFC registradas.
     *
     * Antes cualquier usuario autenticado veia TODAS las tarjetas de
     * TODOS los estudiantes. Ahora: un admin ve el listado completo;
     * cualquier otro usuario solo ve su(s) propia(s) tarjeta(s).
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = NfcCard::with('user', 'registeredBy')->latest();

        if (! $user->hasRole(Role::ADMIN)) {
            $query->where('user_id', (string) $user->getKey());
        }

        $cards = $query->get();
        $canManage = $user->hasRole(Role::ADMIN);

        return Inertia::render('NFC/Index', [
            'cards' => $cards,
            'canManage' => $canManage,
            'availableTransitions' => $canManage ? $cards->mapWithKeys(function (NfcCard $card): array {
                $status = NfcCardStatus::tryFrom((string) $card->status);

                return [(string) $card->getKey() => array_map(
                    fn (NfcCardStatus $target) => $target->value,
                    $status?->ordinaryTargets() ?? [],
                )];
            }) : (object) [],
        ]);
    }

    /**
     * Mostrar formulario para registrar una tarjeta NFC.
     * Operacion sensible de identidad: solo administracion.
     */
    public function create()
    {
        $this->authorize('create', NfcCard::class);

        $users = StudentProfile::with('user')->get()
            ->pluck('user')
            ->filter()
            ->unique(fn (User $user) => (string) $user->getKey())
            ->sortBy('name')
            ->values()
            ->map(fn (User $user) => [
                'id' => (string) $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
            ]);

        return Inertia::render('NFC/Create', [
            'users' => $users,
        ]);
    }

    /**
     * Registrar una nueva tarjeta NFC.
     * Operacion sensible de identidad: solo administracion.
     */
    public function store(Request $request)
    {
        $this->authorize('create', NfcCard::class);

        if (is_string($request->input('uid'))) {
            $request->merge(['uid' => NfcUid::normalize($request->input('uid'))]);
        }

        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'uid' => ['required', 'string', 'max:255', 'unique:nfc_cards,uid'],
        ], [
            'user_id.required' => 'Debes seleccionar un estudiante.',
            'user_id.exists' => 'El estudiante seleccionado no existe.',
            'uid.required' => 'Debes ingresar el UID de la tarjeta.',
            'uid.unique' => 'Esta tarjeta NFC ya esta registrada.',
        ]);

        if (! StudentProfile::where('user_id', (string) $validated['user_id'])->exists()) {
            throw ValidationException::withMessages([
                'user_id' => 'El usuario seleccionado no tiene perfil estudiantil.',
            ]);
        }

        if (NfcCard::where('uid', 'regex', NfcUid::legacyMatch($validated['uid']))->exists()) {
            throw ValidationException::withMessages([
                'uid' => 'Esta tarjeta NFC ya esta registrada.',
            ]);
        }

        $actorId = (string) $request->user()->getKey();

        try {
            $this->mongoTransaction(function () use ($validated, $actorId): void {
                $card = NfcCard::create([
                    'user_id' => (string) $validated['user_id'],
                    'uid' => $validated['uid'],
                    'registered_by' => $actorId,
                    'status' => NfcCardStatus::Active->value,
                    'registered_at' => now(),
                ]);

                $card->credentialEvents()->create([
                    'performed_by' => $actorId,
                    'event_type' => 'registered',
                    'reason' => 'Registro inicial de tarjeta NFC',
                    'previous_status' => null,
                    'new_status' => NfcCardStatus::Active->value,
                ]);

                CredentialChanged::dispatch((string) $card->getKey(), 'nfc', 'registered', NfcCardStatus::Active->value, $actorId);
            });
        } catch (Throwable $failure) {
            if ($this->isDuplicateUidFailure($failure)) {
                throw ValidationException::withMessages([
                    'uid' => 'Esta tarjeta NFC ya esta registrada.',
                ]);
            }

            throw $failure;
        }

        return redirect()
            ->route('nfc-cards.index')
            ->with('success', 'Tarjeta NFC registrada correctamente.');
    }

    private function isDuplicateUidFailure(Throwable $failure): bool
    {
        do {
            if ((int) $failure->getCode() === 11000 && str_contains($failure->getMessage(), 'uid_1')) {
                return true;
            }

            $failure = $failure->getPrevious();
        } while ($failure);

        return false;
    }

    /** Ordinary lifecycle operation; replacement has a dedicated future flow. */
    public function updateStatus(Request $request, NfcCard $nfcCard, ChangeNfcCardStatus $changeStatus)
    {
        $this->authorize('updateStatus', $nfcCard);

        $this->trimReason($request);
        $validated = $request->validate([
            'status' => ['required', Rule::in([
                NfcCardStatus::Active->value,
                NfcCardStatus::Blocked->value,
                NfcCardStatus::Suspended->value,
            ])],
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'status.required' => 'Debes seleccionar un estado.',
            'status.in' => 'El estado seleccionado no es valido.',
            'reason.required' => 'Debes indicar el motivo del cambio.',
            'reason.max' => 'El motivo no puede superar los 500 caracteres.',
        ]);

        $changeStatus->execute(
            $nfcCard,
            NfcCardStatus::from($validated['status']),
            $validated['reason'],
            (string) $request->user()->getKey(),
        );

        return redirect()
            ->route('nfc-cards.index')
            ->with(
                'success',
                'El estado de la tarjeta se actualizo correctamente.'
            );
    }

    public function reportLost(Request $request, NfcCard $nfcCard, ChangeNfcCardStatus $changeStatus)
    {
        $this->authorize('updateStatus', $nfcCard);

        $this->trimReason($request);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $changeStatus->execute(
            $nfcCard,
            NfcCardStatus::Blocked,
            $validated['reason'],
            (string) $request->user()->getKey(),
            true,
        );

        return redirect()->route('nfc-cards.index')->with('success', 'Pérdida de tarjeta NFC registrada.');
    }

    private function trimReason(Request $request): void
    {
        if (is_string($request->input('reason'))) {
            $request->merge(['reason' => trim($request->input('reason'))]);
        }
    }

    /**
     * Mostrar el historial de una tarjeta NFC.
     * El dueno de la tarjeta puede ver su propio historial; un admin
     * puede ver el de cualquiera.
     */
    public function history(NfcCard $nfcCard)
    {
        $this->authorize('view', $nfcCard);

        $events = $nfcCard->credentialEvents()
            ->with('performedBy')
            ->latest()
            ->get();

        return Inertia::render('NFC/History', [
            'card' => $nfcCard->load('user'),
            'events' => $events,
        ]);
    }
}
