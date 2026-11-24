<?php

namespace Database\Seeders\Demo;

/**
 * Registro único de las cuentas de demostración por equipo (E1–E7) de `campus:install --demo`.
 * Sólo laboratorio (local/testing) y sólo DATOS sintéticos: ningún cambio en Policies, Gates, middleware ni 2FA.
 */
final class DemoTeams
{
    /**
     * alias (parte local del correo @campus.test) => [nombre, matrícula].
     * Las cuentas de administración y el profesor no llevan matrícula: no son alumnos, y el seeder de la demo integrada
     * convierte en alumno a toda cuenta con matrícula.
     */
    public const ACCOUNTS = [
        'demo.e1' => ['Demo E1 · Administración de Identidad', null],
        'demo.e1.alumno' => ['Demo E1 · Alumno', '20261002'],
        'demo.e2' => ['Demo E2 · Operación de caja', '20262001'],
        'demo.e2.presidencia' => ['Demo E2 · Presidencia', '20262002'],
        'demo.e2.supervisor' => ['Demo E2 · Supervisión de caja', '20262003'],
        'demo.e2.alumno' => ['Demo E2 · Alumno titular', '20262004'],
        'demo.e2.alumno2' => ['Demo E2 · Alumno destinatario', '20262005'],
        'demo.e2.controles' => ['Demo E2 · Controles financieros', null],
        'demo.e2.consulta' => ['Demo E2 · Consulta de controles', null],
        'demo.e3' => ['Demo E3 · Dueño de tienda', '20263001'],
        'demo.e3.revisor' => ['Demo E3 · Revisión institucional', null],
        'demo.e3.cajero' => ['Demo E3 · Caja de la tienda', '20263003'],
        'demo.e3.comprador' => ['Demo E3 · Comprador', '20263004'],
        'demo.e4' => ['Demo E4 · Propietario de inventario', '20264001'],
        'demo.e4.gerente' => ['Demo E4 · Gerente', '20264002'],
        'demo.e4.almacen' => ['Demo E4 · Responsable de almacén', '20264003'],
        'demo.e4.comprador' => ['Demo E4 · Comprador', '20264005'],
        'demo.e4.alumno' => ['Demo E4 · Alumno sin rol', '20264004'],
        'demo.e4.consulta' => ['Demo E4 · Consulta transversal de inventarios', null],
        'demo.e5' => ['Demo E5 · Personal de Servicios', null],
        'demo.e5.alumno' => ['Demo E5 · Alumno', '20265002'],
        'demo.e5.alumno2' => ['Demo E5 · Alumno en espera', '20265003'],
        'demo.e6' => ['Demo E6 · Presidencia', '20266001'],
        'demo.e6.integrante' => ['Demo E6 · Integrante', '20266002'],
        'demo.e6.revisor' => ['Demo E6 · Comité de becas', '20266003'],
        'demo.e6.staff' => ['Demo E6 · Personal de acceso', '20266004'],
        'demo.e6.gestor' => ['Demo E6 · Gestión de organizaciones', '20266005'],
        'demo.e6.comunicaciones' => ['Demo E6 · Coordinación de comunicaciones', null],
        'demo.e7' => ['Demo E7 · Administración de Recompensas', null],
        'demo.e7.plataforma' => ['Demo E7 · Aprobación de plataforma', null],
        'demo.e7.auditor' => ['Demo E7 · Auditoría', null],
        'demo.e7.profesor' => ['Demo E7 · Profesor', null],
        'demo.e7.alumno' => ['Demo E7 · Alumno', '20267003'],
    ];

    /** Identificadores fijos: la configuración de laboratorio es la misma en toda instalación. */
    public const E2_ORGANIZATION_ID = 'e2de0000000000000000ca5a';

    public const E6_ORGANIZATION_ID = 'e6de00000000000000000c0a';

    /** Saldo inicial sintético del monedero del integrante de E6, para pagar boletos de eventos. */
    public const E6_MEMBER_OPENING_CENTS = 100000;

    public const E6_ORGANIZATION_NAME = 'Asociación Demo E6';

    public const E3_BUSINESS_ID = 'BUS-DEMO-E3';

    public const E3_BUYER_OPENING_CENTS = 100000;

    public const E7_STUDENT_OPENING_CENTS = 100000;

    /** Organización titular de la cuenta institucional de Servicios al Estudiante en Finanzas (receptora de multas, lockers e impresiones). */
    public const E5_SERVICES_ORGANIZATION = 'services';

    /** Saldo inicial sintético del monedero del alumno de E5, para pagar multa, locker e impresión. */
    public const E5_STUDENT_OPENING_CENTS = 100000;

    /** Páginas del apoyo de impresiones sintético del alumno de E5 (en operación lo otorga Comunidad por la API de beneficios). */
    public const E5_PRINT_ALLOWANCE_PAGES = 100;

    /** Regla del ejemplo del diseño (§18): 1 punto por cada $2, máximo 500 por operación. */
    public const E7_STORE_RULE = 'Tienda Demo E3: 1 punto por cada $2';

    public const SECOND_CAMPUS = 'NORTE';

    public const SECOND_PROGRAM = 'ARQ';

    public static function email(string $alias): string
    {
        return $alias.'@campus.test';
    }
}
