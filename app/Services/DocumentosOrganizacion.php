<?php

namespace App\Services;

use App\Models\AuditoriaComunidad;
use App\Models\DocumentoOrganizacion;
use App\Models\Organizacion;
use App\Models\User;
use App\Models\VersionDocumentoOrganizacion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MongoDB\Driver\Exception\ConnectionException;

class DocumentosOrganizacion
{
    public const CATEGORIAS = ['acta' => 'Acta', 'estatutos' => 'Estatutos', 'oficio' => 'Oficio', 'constancia' => 'Constancia', 'otro' => 'Otro documento'];

    public function crear(User $actor, Organizacion $org, array $d, UploadedFile $archivo): array
    {
        return $this->modificar($actor, $org, function () use ($actor, $org, $d, $archivo) {
            $huella = $this->huella($actor, $d, $archivo);
            $previo = DocumentoOrganizacion::where('organizacion_id', (string) $org->id)->where('clave_solicitud', $d['clave_solicitud'])->first();
            if ($previo) {
                abort_unless(hash_equals($previo->huella, $huella), 409, 'La clave ya fue utilizada con otros datos o archivo.');

                return [$previo, false];
            }

            return $this->conArchivo($org, $archivo, function ($f) use ($actor, $org, $d, $huella) {
                $doc = DocumentoOrganizacion::create([...$d, 'organizacion_id' => (string) $org->id, 'estado' => 'activo', 'version' => 1, 'numero_actual' => 1, 'creado_por' => (string) $actor->id, 'huella' => $huella]);
                $v = VersionDocumentoOrganizacion::create([...$f, 'organizacion_id' => (string) $org->id, 'documento_id' => (string) $doc->id, 'numero' => 1, 'cargado_por' => (string) $actor->id, 'clave_solicitud' => $d['clave_solicitud'], 'huella' => $huella, 'motivo' => 'Carga inicial.']);
                $doc->update(['version_actual_id' => (string) $v->id]);
                $this->auditar($doc, $actor, 'organizacion_documento_creado', null, ['documento' => $doc->toArray(), 'version_id' => (string) $v->id, 'sha256' => $v->sha256]);

                return [$doc, true];
            });
        });
    }

    public function nuevaVersion(User $actor, Organizacion $org, string $id, array $d, UploadedFile $archivo): array
    {
        return $this->modificar($actor, $org, function () use ($actor, $org, $id, $d, $archivo) {
            $doc = $this->buscar($org, $id);
            $huella = $this->huella($actor, $d, $archivo);
            $previa = VersionDocumentoOrganizacion::where('documento_id', $id)->where('clave_solicitud', $d['clave_solicitud'])->first();
            if ($previa) {
                abort_unless(hash_equals($previa->huella, $huella), 409, 'La clave ya fue utilizada con otros datos o archivo.');

                return [$doc, false];
            }
            $this->editable($doc, $d['version']);

            return $this->conArchivo($org, $archivo, function ($f) use ($actor, $org, $id, $d, $doc, $huella) {
                $antes = $doc->toArray();
                $v = VersionDocumentoOrganizacion::create([...$f, 'organizacion_id' => (string) $org->id, 'documento_id' => $id, 'numero' => $doc->numero_actual + 1, 'cargado_por' => (string) $actor->id, 'clave_solicitud' => $d['clave_solicitud'], 'huella' => $huella, 'motivo' => $d['motivo']]);
                $doc->update(['numero_actual' => $v->numero, 'version_actual_id' => (string) $v->id, 'version' => $doc->version + 1]);
                $this->auditar($doc, $actor, 'organizacion_documento_version', $antes, ['documento' => $doc->toArray(), 'version_id' => (string) $v->id, 'sha256' => $v->sha256, 'motivo' => $d['motivo']]);

                return [$doc, true];
            });
        });
    }

    public function editar(User $actor, Organizacion $org, string $id, array $d): DocumentoOrganizacion
    {
        return $this->modificar($actor, $org, fn () => DB::connection('mongodb')->transaction(function () use ($actor, $org, $id, $d) {
            $doc = $this->buscar($org, $id);
            $this->editable($doc, $d['version']);
            $antes = $doc->toArray();
            $doc->update([...$d, 'version' => $doc->version + 1]);
            $this->auditar($doc, $actor, 'organizacion_documento_editado', $antes, $doc->toArray());

            return $doc;
        }));
    }

    public function retirar(User $actor, Organizacion $org, string $id, array $d): DocumentoOrganizacion
    {
        return $this->modificar($actor, $org, fn () => DB::connection('mongodb')->transaction(function () use ($actor, $org, $id, $d) {
            $doc = $this->buscar($org, $id);
            if ($doc->estado === 'retirado') {
                return $doc;
            }
            $this->editable($doc, $d['version']);
            $antes = $doc->toArray();
            $doc->update(['estado' => 'retirado', 'version' => $doc->version + 1, 'retirado_en' => now(), 'retirado_por' => (string) $actor->id, 'motivo_retiro' => $d['motivo']]);
            $this->auditar($doc, $actor, 'organizacion_documento_retirado', $antes, $doc->toArray());

            return $doc;
        }));
    }

    private function modificar(User $actor, Organizacion $org, callable $fn): mixed
    {
        return app(ResponsabilidadesComunidad::class)->ejecutar(function () use ($actor, $org, $fn) {
            Gate::forUser($actor)->authorize('update', $org);

            return $fn();
        });
    }

    private function buscar(Organizacion $org, string $id): DocumentoOrganizacion
    {
        return DocumentoOrganizacion::where('organizacion_id', (string) $org->id)->findOrFail($id);
    }

    private function editable(DocumentoOrganizacion $doc, int $version): void
    {
        abort_unless($doc->estado === 'activo', 422, 'El documento retirado conserva su historial y no admite cambios.');
        abort_unless($doc->version === $version, 409, 'El documento cambió. Actualiza antes de continuar.');
    }

    private function huella(User $actor, array $datos, UploadedFile $archivo): string
    {
        return hash('sha256', json_encode([$datos, (string) $actor->id, hash_file('sha256', $archivo->getRealPath())], JSON_THROW_ON_ERROR));
    }

    private function conArchivo(Organizacion $org, UploadedFile $archivo, callable $fn): mixed
    {
        $ruta = $archivo->storeAs((string) $org->id, Str::uuid().'.'.$archivo->extension(), 'organizacion_documentos');
        try {
            $nombre = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $archivo->getClientOriginalName()))), 0, 180);

            return DB::connection('mongodb')->transaction(fn () => $fn(['ruta' => $ruta, 'nombre' => $nombre, 'tipo' => $archivo->getMimeType(), 'tamano' => $archivo->getSize(), 'sha256' => hash_file('sha256', $archivo->getRealPath())]));
        } catch (\Throwable $e) {
            // Ante un commit de resultado incierto conservamos el archivo privado para conciliación.
            $incierto = false;
            for ($causa = $e; $causa; $causa = $causa->getPrevious()) {
                $incierto = $incierto || $causa instanceof ConnectionException || (method_exists($causa, 'hasErrorLabel') && $causa->hasErrorLabel('UnknownTransactionCommitResult'));
            }
            if (! $incierto) {
                try {
                    Storage::disk('organizacion_documentos')->delete($ruta);
                } catch (\Throwable $limpieza) {
                    report($limpieza);
                }
            }
            throw $e;
        }
    }

    private function auditar(DocumentoOrganizacion $doc, User $actor, string $accion, ?array $antes, array $despues): void
    {
        AuditoriaComunidad::create(['organizacion_id' => $doc->organizacion_id, 'usuario_id' => (string) $actor->id, 'accion' => $accion, 'entidad_id' => (string) $doc->id, 'antes' => $antes, 'despues' => $despues]);
    }
}
