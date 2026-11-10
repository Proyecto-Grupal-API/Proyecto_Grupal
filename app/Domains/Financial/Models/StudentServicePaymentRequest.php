<?php

namespace App\Domains\Financial\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cobro de Servicios al Estudiante (locker o impresiones) que el alumno
 * autoriza en Finanzas; se transfiere a la cuenta institucional.
 */
class StudentServicePaymentRequest extends Model
{
    protected $connection = 'sqlsrv';

    protected $table = 'student_service_payment_requests';

    protected $guarded = ['id'];

    protected $casts = ['amount_cents' => 'integer'];
}
