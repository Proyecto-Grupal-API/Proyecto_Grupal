<?php

namespace App\Models\StudentServices\Lockers;

use App\Services\StudentServices\Payments\Money;
use Carbon\CarbonInterface;
use MongoDB\Laravel\Eloquent\Model;

/**
 * @property-read string $id
 * @property string $code
 * @property string $name
 * @property CarbonInterface|null $starts_at
 * @property CarbonInterface|null $ends_at
 * @property array<string, int>|null $prices_cents
 * @property string $status
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class LockerPeriod extends Model
{
    public const STATUSES = [
        'active',
        'closed',
    ];

    protected $connection = 'mongodb';

    protected $table = 'locker_periods';

    protected $fillable = [
        'code',
        'name',
        'starts_at',
        'ends_at',
        'prices_cents',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * Costo del tamaño en centavos. Los periodos guardados antes del cambio
     * a centavos conservan `prices` en pesos (Decimal128) hasta que corre la
     * migración; por eso se lee como respaldo.
     */
    public function priceCentsFor(
        string $size
    ): ?int {
        $cents = data_get(
            $this->prices_cents,
            $size
        );

        if ($cents !== null) {
            return (int) $cents;
        }

        $legacy = data_get(
            $this->getAttribute('prices'),
            $size
        );

        return $legacy === null
            ? null
            : Money::toCents($legacy);
    }

    /**
     * Costo del tamaño en pesos con dos decimales ("150.00"), para la UI.
     */
    public function priceFor(
        string $size
    ): ?string {
        return Money::toPesos(
            $this->priceCentsFor($size)
        );
    }

    /**
     * @return array{
     *     id: string,
     *     code: string,
     *     name: string,
     *     starts_at: string|null,
     *     ends_at: string|null,
     *     prices: array{small: string|null, medium: string|null, large: string|null},
     *     status: string
     * }
     */
    public function toPayload(): array
    {
        return [
            'id' => (string) $this->id,
            'code' => $this->code,
            'name' => $this->name,

            'starts_at' => $this->starts_at?->format(
                'Y-m-d'
            ),

            'ends_at' => $this->ends_at?->format(
                'Y-m-d'
            ),

            'prices' => [
            'small' => $this->priceFor(
                'small'
            ),

            'medium' => $this->priceFor(
                'medium'
            ),

            'large' => $this->priceFor(
                'large'
            ),
            ],

            'status' => $this->status,
        ];
    }
}
