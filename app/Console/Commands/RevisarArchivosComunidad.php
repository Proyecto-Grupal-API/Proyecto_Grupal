<?php

namespace App\Console\Commands;

use App\Models\SolicitudBeca;
use App\Models\VersionDocumentoOrganizacion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class RevisarArchivosComunidad extends Command
{
    protected $signature = 'comunidad:revisar-archivos {--json : Emite el informe de diagnóstico como JSON}';

    protected $description = 'Revisa referencias y archivos privados de Comunidad sin borrar ni reparar datos.';

    public function handle(): int
    {
        $report = ['version' => 1, 'solo_lectura' => true, 'iniciado_en' => now()->toIso8601String()];
        $exit = self::SUCCESS;
        try {
            $discos = [];
            $observaciones = false;
            foreach (['becas', 'organizacion_documentos'] as $nombre) {
                $disk = Storage::disk($nombre);
                $rutas = [];
                $resultado = ['referencias' => 0, 'referencias_sin_archivo' => [], 'referencias_invalidas' => []];
                foreach ($this->referencias($nombre) as $ref) {
                    $resultado['referencias']++;
                    if (! $this->rutaValida($ref['ruta'])) {
                        $resultado['referencias_invalidas'][] = ['registro_id' => $ref['registro_id'], 'archivo_id' => $ref['archivo_id']];

                        continue;
                    }
                    $rutas[$ref['ruta']] = true;
                    if (! $disk->exists($ref['ruta'])) {
                        $resultado['referencias_sin_archivo'][] = $ref;
                    }
                }
                $archivos = $disk->allFiles();
                sort($archivos, SORT_STRING);
                $resultado['archivos'] = count($archivos);
                $resultado['archivos_sin_referencia_observada'] = array_values(array_diff($archivos, array_keys($rutas)));
                $observaciones = $observaciones || $resultado['referencias_sin_archivo'] || $resultado['referencias_invalidas'] || $resultado['archivos_sin_referencia_observada'];
                $discos[$nombre] = $resultado;
            }
            $report += ['estado' => $observaciones ? 'con_observaciones' : 'sin_observaciones', 'discos' => $discos, 'finalizado_en' => now()->toIso8601String()];
            $json = json_encode($report, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            report($e);
            $report = [...$report, 'estado' => 'incompleta', 'discos' => [], 'finalizado_en' => now()->toIso8601String()];
            $json = json_encode($report, JSON_THROW_ON_ERROR);
            $exit = self::FAILURE;
        }
        if ($this->option('json')) {
            $this->line($json);
        } else {
            $this->line('Revisión de archivos: '.$report['estado']);
            $this->table(['Disco', 'Referencias', 'Archivos', 'Sin archivo', 'Ruta inválida', 'Sin referencia observada'], collect($report['discos'])->map(fn ($d, $nombre) => [$nombre, $d['referencias'], $d['archivos'], count($d['referencias_sin_archivo']), count($d['referencias_invalidas']), count($d['archivos_sin_referencia_observada'])])->values()->all());
            $this->line('Diagnóstico de solo lectura. Revalidar los candidatos: puede haber cargas en curso. Detalles con --json.');
        }

        return $exit;
    }

    private function referencias(string $disk): iterable
    {
        if ($disk === 'becas') {
            foreach (SolicitudBeca::select('_id', 'documentos')->cursor() as $s) {
                if (! is_array($s->documentos ?? [])) {
                    throw new \UnexpectedValueException('Los metadatos de documentos no se pudieron inspeccionar.');
                }
                foreach ($s->documentos ?? [] as $doc) {
                    yield ['registro_id' => (string) $s->id, 'archivo_id' => is_array($doc) && is_string($doc['id'] ?? null) ? $doc['id'] : null, 'ruta' => is_array($doc) ? ($doc['ruta'] ?? null) : null];
                }
            }
        } else {
            // Las versiones históricas y retiradas también protegen su archivo.
            foreach (VersionDocumentoOrganizacion::select('_id', 'documento_id', 'ruta')->cursor() as $v) {
                yield ['registro_id' => (string) $v->documento_id, 'archivo_id' => (string) $v->id, 'ruta' => $v->ruta];
            }
        }
    }

    private function rutaValida(mixed $ruta): bool
    {
        return is_string($ruta) && $ruta !== '' && ! str_contains($ruta, '\\')
            && ! preg_match('/[\x00-\x1F\x7F]|^[a-z]:/i', $ruta)
            && ! array_intersect(explode('/', $ruta), ['', '.', '..']);
    }
}
