<?php

namespace App\Http\Controllers\StudentServices\Rentals;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentServices\Rentals\ReturnRentalRequest;
use App\Http\Requests\StudentServices\Rentals\StoreRentalRequest;
use App\Models\StudentServices\Rentals\Asset;
use App\Models\StudentServices\Rentals\Rental;
use App\Services\StudentServices\Rentals\RentalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

class RentalController extends Controller
{
    public function __construct(
        private readonly RentalService $rentalService
    ) {
    }

    public function index(
        Request $request
    ): Response {
        /*
         * Actualiza automáticamente las rentas
         * que ya superaron su fecha límite.
         */
        $this->rentalService
            ->refreshOverdueRentals();

        $studentId =
            $this->getStudentId(
                $request
            );

        /*
         * Catálogo local de equipos.
         *
         * Más adelante estos datos podrán
         * sustituirse por la integración
         * real con el inventario del Equipo 4.
         */
        $equipment = Asset::query()
            ->orderBy('name')
            ->get()
            ->map(
                fn (Asset $asset) =>
                $this->assetPayload(
                    $asset
                )
            )
            ->values();

        /*
         * Solo mostramos las rentas
         * actualmente activas o vencidas
         * del usuario autenticado.
         */
        $rentals = Rental::query()
            ->where(
                'student_id',
                $studentId
            )
            ->whereIn(
                'status',
                [
                    'active',
                    'overdue',
                ]
            )
            ->orderBy(
                'requested_at',
                'desc'
            )
            ->get()
            ->map(
                fn (Rental $rental) =>
                $this->rentalPayload(
                    $rental
                )
            )
            ->values();

        return Inertia::render(
            'student-services/rentals/Index',
            [
                'equipment' =>
                    $equipment,

                'rentals' =>
                    $rentals,
            ]
        );
    }

    public function store(
        StoreRentalRequest $request
    ): RedirectResponse {
        $data =
            $request->validated();

        try {
            $asset =
                Asset::findOrFail(
                    $data['asset_id']
                );

            $studentId =
                $this->getStudentId(
                    $request
                );

            $this->rentalService
                ->createRental(
                    $asset,
                    $studentId,
                    $data['due_at'],
                    $data['notes'] ?? null
                );

            return back()->with(
                'success',
                'Renta registrada correctamente.'
            );
        } catch (
        RuntimeException $exception
        ) {
            return back()
                ->withErrors([
                    'rental' =>
                        $exception
                            ->getMessage(),
                ])
                ->withInput();
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors([
                    'rental' =>
                        'No fue posible registrar la renta.',
                ])
                ->withInput();
        }
    }

    public function cancel(
        Request $request,
        string $rentalId
    ): RedirectResponse {
        try {
            $rental =
                Rental::findOrFail(
                    $rentalId
                );

            $this->validateRentalOwner(
                $request,
                $rental
            );

            $this->rentalService
                ->cancelRental(
                    $rental
                );

            return back()->with(
                'success',
                'Renta cancelada correctamente.'
            );
        } catch (
        RuntimeException $exception
        ) {
            return back()
                ->withErrors([
                    'rental' =>
                        $exception
                            ->getMessage(),
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors([
                    'rental' =>
                        'No fue posible cancelar la renta.',
                ]);
        }
    }

    public function returnRental(
        ReturnRentalRequest $request,
        string $rentalId
    ): RedirectResponse {
        $data =
            $request->validated();

        try {
            $rental =
                Rental::findOrFail(
                    $rentalId
                );

            $this->validateRentalOwner(
                $request,
                $rental
            );

            $this->rentalService
                ->returnRental(
                    $rental,
                    $data['condition'],
                    $data['notes'] ?? null
                );

            return back()->with(
                'success',
                'Devolución registrada correctamente.'
            );
        } catch (
        RuntimeException $exception
        ) {
            return back()
                ->withErrors([
                    'rental' =>
                        $exception
                            ->getMessage(),
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors([
                    'rental' =>
                        'No fue posible registrar la devolución.',
                ]);
        }
    }

    private function getStudentId(
        Request $request
    ): string {
        $user =
            $request->user();

        if ($user === null) {
            throw new RuntimeException(
                'No se encontró un usuario autenticado.'
            );
        }

        return (string)
        $user->getAuthIdentifier();
    }

    private function validateRentalOwner(
        Request $request,
        Rental $rental
    ): void {
        $studentId =
            $this->getStudentId(
                $request
            );

        if (
            (string) $rental->student_id
            !==
            $studentId
        ) {
            throw new RuntimeException(
                'No puedes modificar una renta que pertenece a otro usuario.'
            );
        }
    }

    private function assetPayload(
        Asset $asset
    ): array {
        return [
            'id' =>
                (string) $asset->id,

            'inventory_item_id' =>
                $asset->inventory_item_id,

            'name' =>
                $asset->name,

            'category' =>
                $asset->category,

            'location' =>
                $asset->location,

            'status' =>
                $asset->status,

            'description' =>
                $asset->description,
        ];
    }

    private function rentalPayload(
        Rental $rental
    ): array {
        $asset =
            Asset::find(
                (string)
                $rental->asset_id
            );

        return [
            'id' =>
                (string) $rental->id,

            'asset_id' =>
                (string)
                $rental->asset_id,

            'inventory_item_id' =>
                $rental
                    ->inventory_item_id,

            'student_id' =>
                $rental->student_id,

            'equipment_name' =>
                $asset?->name ??
                'Equipo no disponible',

            'requested_at' =>
                $rental->requested_at
                    ?->toISOString(),

            'due_at' =>
                $rental->due_at
                    ?->toISOString(),

            'returned_at' =>
                $rental->returned_at
                    ?->toISOString(),

            'status' =>
                $rental->status,

            'notes' =>
                $rental->notes,
        ];
    }
}
