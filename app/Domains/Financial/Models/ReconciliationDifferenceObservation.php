<?php

namespace App\Domains\Financial\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 2.10 Una corrida de conciliación observó una diferencia.
 */
class ReconciliationDifferenceObservation extends Model
{
    protected $connection = 'sqlsrv';

    protected $table = 'reconciliation_difference_observations';

    protected $fillable = [
        'reconciliation_id',
        'difference_id',
        'is_new',
    ];

    protected $casts = [
        'is_new' => 'boolean',
    ];
}
