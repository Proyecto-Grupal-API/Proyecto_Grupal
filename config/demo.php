<?php

/*
 * Demostración por equipo (`campus:install --demo`, sólo local/testing).
 * La contraseña de las cuentas sintéticas NO está en el repositorio: llega por entorno desde un archivo privado.
 * Si falta, el instalador la genera y la deja en el archivo indicado con `--env-file` (permisos 600); sin ninguna de
 * las dos cosas, se niega a sembrar las cuentas.
 */
return [
    'password' => env('CAMPUS_DEMO_PASSWORD'),
];
