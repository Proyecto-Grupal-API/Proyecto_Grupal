<?php
namespace App\Services\StudentServices;
use Carbon\Carbon;
class ServiceClock
{
    public static function parse($value): Carbon
    {
        return Carbon::parse($value, config('integracion_servicios.timezone'))->setTimezone(config('integracion_servicios.timezone'));
    }
}
