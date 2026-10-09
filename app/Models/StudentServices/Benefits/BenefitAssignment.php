<?php

namespace App\Models\StudentServices\Benefits;

use MongoDB\Laravel\Eloquent\Model;

/**
 * Asignación de un beneficio de servicio (beca de locker o de
 * impresiones) solicitada por otro dominio, hoy Comunidad (Equipo 6).
 *
 * Es la referencia que el Equipo 6 conserva: el recurso real vive en su
 * módulo (locker_assignments para lockers; el saldo de páginas aquí
 * mismo para impresiones, que se consume al pagar órdenes de 5.8).
 *
 * status interno: processing | active | cancelled. El estado que ve la
 * API (asignado, entregado, en_uso, consumido, vencido, finalizado,
 * cancelado) se deriva al presentar.
 */
class BenefitAssignment extends Model
{
    public const BENEFIT_LOCKER = 'locker';

    public const BENEFIT_PRINTS = 'impresiones';

    protected $connection = 'mongodb';

    protected $collection = 'benefit_assignments';

    protected $fillable = [
        'folio',
        'client_id',
        'idempotency_key',
        'request_hash',
        'benefit',
        'student_id',
        'organization_id',
        'call_id',
        'application_id',
        'origin_folio',
        'quantity',
        'consumed',
        'remaining',
        'valid_from',
        'valid_until',
        'status',
        'locker_assignment_id',
        'locker_code',
        'locker_building',
        'locker_zone',
        'locker_assignment_folio',
        'consumptions',
        'cancellation',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'consumed' => 'integer',
            'remaining' => 'integer',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
        ];
    }
}
