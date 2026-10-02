<?php

namespace App\Http\Controllers\StudentServices\Lockers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentServices\Lockers\SaveLockerRequest;
use App\Models\StudentServices\Lockers\Locker;
use App\Models\StudentServices\Lockers\LockerPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use MongoDB\BSON\ObjectId;

class LockerController extends Controller
{
    public function index(): Response
    {
        $lockers = Locker::query()
            ->orderBy('building')
            ->orderBy('zone')
            ->orderBy('code')
            ->get()
            ->map(fn (Locker $locker) => $locker->toPayload())
            ->values();

        $periods = LockerPeriod::query()
            ->where('status', 'active')
            ->orderBy('starts_at')
            ->get()
            ->map(fn (LockerPeriod $period) => $period->toPayload())
            ->values();

        return Inertia::render(
            'student-services/lockers/Index',
            [
                'lockers' => $lockers,
                'periods' => $periods,

                'summary' => [
                    'total' => $lockers->count(),

                    'available' => $lockers
                        ->where('status', 'available')
                        ->count(),

                    'reserved' => $lockers
                        ->where('status', 'reserved')
                        ->count(),

                    'occupied' => $lockers
                        ->where('status', 'occupied')
                        ->count(),

                    'maintenance' => $lockers
                        ->where('status', 'maintenance')
                        ->count(),
                ],
            ]
        );
    }

    public function store(
        SaveLockerRequest $request
    ): RedirectResponse {
        $data = $request->validated();

        $code = Str::upper(
            trim($data['code'])
        );

        $qrCode = 'QR-' . $code;

        $exists = Locker::query()
            ->where('code', $code)
            ->orWhere('qr_code', $qrCode)
            ->exists();

        if ($exists) {
            return back()->withErrors([
                'code' =>
                    'Ya existe un locker con ese código.',
            ]);
        }

        Locker::create([
            'code' => $code,

            'qr_code' => $qrCode,

            'building' =>
                trim($data['building']),

            'zone' =>
                trim($data['zone']),

            'size' =>
                $data['size'],

            'status' =>
                'available',

            'notes' =>
                $this->cleanText(
                    $data['notes'] ?? null
                ),
        ]);

        return back()->with(
            'success',
            'Locker registrado correctamente.'
        );
    }

    public function update(
        SaveLockerRequest $request,
        string $lockerId
    ): RedirectResponse {
        $locker =
            Locker::findOrFail(
                $lockerId
            );

        $data =
            $request->validated();

        $code = Str::upper(
            trim($data['code'])
        );

        $building =
            trim($data['building']);

        $zone =
            trim($data['zone']);

        $codeExists =
            Locker::query()
                ->where(
                    'code',
                    $code
                )
                ->where(
                    '_id',
                    '!=',
                    new ObjectId(
                        (string)
                        $locker->id
                    )
                )
                ->exists();

        if ($codeExists) {
            return back()->withErrors([
                'code' =>
                    'Ya existe otro locker con ese código.',
            ]);
        }

        /*
         * Un locker ocupado o reservado no
         * puede cambiar código, tamaño o ubicación.
         */
        if (
            in_array(
                $locker->status,
                ['occupied', 'reserved'],
                true
            )
        ) {
            $changed =
                $locker->code !==
                $code
                ||
                $locker->size !==
                $data['size']
                ||
                $locker->building !==
                $building
                ||
                $locker->zone !==
                $zone;

            if ($changed) {
                return back()
                    ->withErrors([
                        'status' =>
                            'No puedes cambiar el código, tamaño ni ubicación de un locker ocupado o reservado.',
                    ]);
            }
        }

        /*
         * qr_code no se modifica:
         * representa el código físico del locker.
         */
        $locker->update([
            'code' => $code,

            'building' =>
                $building,

            'zone' =>
                $zone,

            'size' =>
                $data['size'],

            'notes' =>
                $this->cleanText(
                    $data['notes'] ?? null
                ),
        ]);

        return back()->with(
            'success',
            'Locker actualizado correctamente.'
        );
    }

    public function markMaintenance(
        string $lockerId
    ): RedirectResponse {
        $locker =
            Locker::findOrFail(
                $lockerId
            );

        if (
            $locker->status !==
            'available'
        ) {
            return back()
                ->withErrors([
                    'status' =>
                        'Solo un locker disponible puede enviarse a mantenimiento. Si está ocupado o reservado, primero debe liberarse.',
                ]);
        }

        $locker->update([
            'status' =>
                'maintenance',
        ]);

        return back()->with(
            'success',
            'Locker enviado a mantenimiento.'
        );
    }

    public function restoreAvailable(
        string $lockerId
    ): RedirectResponse {
        $locker =
            Locker::findOrFail(
                $lockerId
            );

        if (
            $locker->status !==
            'maintenance'
        ) {
            return back()
                ->withErrors([
                    'status' =>
                        'Solo un locker en mantenimiento puede regresar a disponible.',
                ]);
        }

        $locker->update([
            'status' =>
                'available',
        ]);

        return back()->with(
            'success',
            'Locker disponible nuevamente.'
        );
    }

    private function cleanText(
        ?string $value
    ): ?string {
        $value = trim(
            (string) $value
        );

        return $value === ''
            ? null
            : $value;
    }
}
