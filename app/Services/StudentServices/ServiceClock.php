<?php
namespace App\Services\StudentServices;
use Carbon\Carbon;
class ServiceClock
{
    public static function parse($value): Carbon
    {
        return Carbon::parse($value, config('integracion_servicios.timezone'))->setTimezone(config('integracion_servicios.timezone'));
    }

    /** Instante guardado (UTC) expresado en la zona horaria del servicio, para mostrarlo a las personas. */
    public static function local(\DateTimeInterface $value, ?string $timezone = null): Carbon
    {
        return Carbon::instance($value)->setTimezone($timezone ?? config('integracion_servicios.timezone'));
    }
    public static function localOrNull(?\DateTimeInterface $value, ?string $timezone = null): ?Carbon
    {
        return $value === null ? null : self::local($value, $timezone);
    }
}
