<?php

namespace App\Console\Commands;

use App\Services\Directory\EnrollmentIntegrityPreflight;
use Illuminate\Console\Command;

/**
 * Preflight de integridad de matrículas del directorio E1 (contrato D1). Sólo lectura: no repara datos ni cambia
 * IDENTITY_ENROLLMENT_INTEGRITY_VERIFIED; quien opera la instalación decide atestar con este resultado.
 * Nunca imprime matrículas, nombres ni identificadores.
 */
class IdentityDirectoryPreflight extends Command
{
    protected $signature = 'identity:directory-preflight {--json : Resultado en JSON}';

    protected $description = 'Comprueba (sólo lectura) la integridad de las matrículas que exige el directorio de Identidad';

    private const LABELS = [
        'unique_index_user_id' => 'Índice único por usuario en student_profiles',
        'unique_index_enrollment_number' => 'Índice único por matrícula en student_profiles',
        'canonical_enrollment_numbers' => 'Matrículas normalizadas (mayúsculas, sin espacios, formato válido)',
        'no_duplicate_enrollment_after_normalization' => 'Sin matrículas repetidas al normalizar (variantes históricas)',
        'valid_user_ids' => 'Identificadores de usuario válidos',
        'one_profile_per_user' => 'Un solo perfil por usuario',
        'no_orphan_profiles' => 'Sin perfiles huérfanos (el usuario existe)',
    ];

    public function handle(EnrollmentIntegrityPreflight $preflight): int
    {
        $report = $preflight->run();
        if ($this->option('json')) {
            $this->line(json_encode($report + ['checked_at' => now()->toISOString()], JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['Comprobación', 'Resultado', 'Casos'], array_map(fn ($key, $check) => [self::LABELS[$key] ?? $key,
                $check['passed'] ? 'OK' : 'FALLA', $check['count']], array_keys($report['checks']), $report['checks']));
            $this->line("Perfiles revisados: {$report['profiles']}");
            $this->line($report['passed']
                ? 'Resultado: APROBADO. Quien opera la instalación puede atestar IDENTITY_ENROLLMENT_INTEGRITY_VERIFIED=true.'
                : 'Resultado: NO APROBADO. No ateste la integridad; corrija los datos en una fase autorizada y repita el preflight.');
        }

        return $report['passed'] ? self::SUCCESS : self::FAILURE;
    }
}
