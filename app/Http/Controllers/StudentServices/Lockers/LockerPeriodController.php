<?php

namespace App\Http\Controllers\StudentServices\Lockers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentServices\Lockers\SaveLockerPeriodRequest;
use App\Models\StudentServices\Lockers\Locker;
use App\Models\StudentServices\Lockers\LockerPeriod;
use App\Services\StudentServices\Payments\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use MongoDB\BSON\ObjectId;

class LockerPeriodController extends Controller
{
    public function index(): Response
    {
        $periods =
            LockerPeriod::query()
                ->orderBy(
                    'starts_at',
                    'desc'
                )
                ->get()
                ->map(
                    fn (
                        LockerPeriod $period
                    ) => $period->toPayload()
                )
                ->values();

        return Inertia::render(
            'student-services/lockers/Periods',
            [
                'periods' => $periods,
            ]
        );
    }

    public function store(
        SaveLockerPeriodRequest $request
    ): RedirectResponse {
        $data =
            $request->validated();

        $code = Str::upper(
            trim($data['code'])
        );

        if (
            LockerPeriod::query()
                ->where(
                    'code',
                    $code
                )
                ->exists()
        ) {
            return back()
                ->withErrors([
                    'code' => 'Ya existe un periodo con ese código.',
                ]);
        }

        LockerPeriod::create([
            'code' => $code,

            'name' => trim(
                $data['name']
            ),

            'starts_at' => Carbon::parse(
                $data['starts_at']
            )->startOfDay(),

            'ends_at' => Carbon::parse(
                $data['ends_at']
            )->endOfDay(),

            'prices_cents' => $this->buildPrices(
                $data['prices']
            ),

            'status' => 'active',
        ]);

        return back()->with(
            'success',
            'Periodo registrado correctamente.'
        );
    }

    public function update(
        SaveLockerPeriodRequest $request,
        string $periodId
    ): RedirectResponse {
        $period =
            LockerPeriod::findOrFail(
                $periodId
            );

        if (
            $period->status !==
            'active'
        ) {
            return back()
                ->withErrors([
                    'status' => 'Un periodo cerrado no se puede editar.',
                ]);
        }

        $data =
            $request->validated();

        $code = Str::upper(
            trim($data['code'])
        );

        $codeExists =
            LockerPeriod::query()
                ->where(
                    'code',
                    $code
                )
                ->where(
                    '_id',
                    '!=',
                    new ObjectId(
                        (string)
                        $period->id
                    )
                )
                ->exists();

        if ($codeExists) {
            return back()
                ->withErrors([
                    'code' => 'Ya existe otro periodo con ese código.',
                ]);
        }

        $period->update([
            'code' => $code,

            'name' => trim(
                $data['name']
            ),

            'starts_at' => Carbon::parse(
                $data['starts_at']
            )->startOfDay(),

            'ends_at' => Carbon::parse(
                $data['ends_at']
            )->endOfDay(),

            'prices_cents' => $this->buildPrices(
                $data['prices']
            ),
        ]);

        return back()->with(
            'success',
            'Periodo actualizado correctamente.'
        );
    }

    public function close(
        string $periodId
    ): RedirectResponse {
        $period =
            LockerPeriod::findOrFail(
                $periodId
            );

        if (
            $period->status !==
            'active'
        ) {
            return back()
                ->withErrors([
                    'status' => 'El periodo ya está cerrado.',
                ]);
        }

        $period->update([
            'status' => 'closed',
        ]);

        return back()->with(
            'success',
            'Periodo cerrado correctamente.'
        );
    }

    /**
     * Convierte los costos capturados en pesos a centavos enteros.
     *
     * @param  array<string, mixed>  $prices
     * @return array<string, int>
     */
    private function buildPrices(
        array $prices
    ): array {
        $result = [];

        foreach (
            Locker::SIZES as $size
        ) {
            $result[$size] =
                Money::toCents(
                    $prices[$size]
                );
        }

        return $result;
    }
}
