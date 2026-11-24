<?php

namespace Database\Seeders\Demo;

use RuntimeException;

/**
 * Contraseña de las cuentas sintéticas de demostración (adenda 21.E).
 *
 * Sale sólo de la configuración privada de la instalación (`CAMPUS_DEMO_PASSWORD`, que `campus:install --demo --env-file`
 * genera una vez en el archivo privado). Ningún sembrador la escribe en el repositorio ni usa un valor de reserva.
 */
final class DemoPassword
{
    public static function value(): string
    {
        $password = config('demo.password');
        if (! is_string($password) || strlen($password) < 12) {
            throw new RuntimeException('Falta la contraseña de demostración: define CAMPUS_DEMO_PASSWORD en un archivo privado (12 caracteres o más) o ejecuta campus:install --demo --env-file=<archivo privado>.');
        }

        return $password;
    }
}
