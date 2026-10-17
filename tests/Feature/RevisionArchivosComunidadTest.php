<?php

namespace Tests\Feature;

use App\Models\AuditoriaComunidad;
use App\Models\DocumentoOrganizacion;
use App\Models\SolicitudBeca;
use App\Models\VersionDocumentoOrganizacion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

class RevisionArchivosComunidadTest extends TestCase
{
    use RefreshMongoDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('becas');
        Storage::fake('organizacion_documentos');
    }

    private function revisar(): array
    {
        $exit = Artisan::call('comunidad:revisar-archivos', ['--json' => true]);

        return [$exit, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
    }

    public function test_empty_storage_is_reported_without_mutating_business_data(): void
    {
        [$exit, $report] = $this->revisar();
        $this->assertSame(0, $exit);
        $this->assertSame('sin_observaciones', $report['estado']);
        $this->assertTrue($report['solo_lectura']);
        foreach ($report['discos'] as $disk) {
            $this->assertSame(0, $disk['referencias']);
            $this->assertSame(0, $disk['archivos']);
        }
        $this->assertSame(0, AuditoriaComunidad::count());
    }

    public function test_missing_files_and_unreferenced_candidates_are_reported_but_never_deleted(): void
    {
        $s = SolicitudBeca::create(['folio' => 'BECA-REVISION', 'estado' => 'retirada', 'documentos' => [
            ['id' => 'actual', 'ruta' => 'solicitud/actual.pdf', 'nombre' => 'Nombre personal que no debe aparecer.pdf'],
            ['id' => 'faltante', 'ruta' => 'solicitud/faltante.pdf'],
        ]]);
        Storage::disk('becas')->put('solicitud/actual.pdf', 'contenido privado');
        Storage::disk('becas')->put('solicitud/candidato.pdf', 'commit incierto');
        $antes = $s->toArray();
        $documentos = $s->fresh()->documentos;
        [$exit, $report] = $this->revisar();
        $this->assertSame(0, $exit);
        $this->assertSame('con_observaciones', $report['estado']);
        $disk = $report['discos']['becas'];
        $this->assertSame(2, $disk['referencias']);
        $this->assertSame(2, $disk['archivos']);
        $this->assertSame([['registro_id' => (string) $s->id, 'archivo_id' => 'faltante', 'ruta' => 'solicitud/faltante.pdf']], $disk['referencias_sin_archivo']);
        $this->assertSame(['solicitud/candidato.pdf'], $disk['archivos_sin_referencia_observada']);
        $this->assertSame($antes, $s->fresh()->toArray());
        $this->assertSame($documentos, $s->fresh()->documentos);
        Storage::disk('becas')->assertExists(['solicitud/actual.pdf', 'solicitud/candidato.pdf']);
        $this->assertStringNotContainsString('Nombre personal', json_encode($report));
        $this->assertSame(0, AuditoriaComunidad::count());
    }

    public function test_historical_and_retired_organization_versions_remain_referenced(): void
    {
        $d = DocumentoOrganizacion::create(['estado' => 'retirado', 'titulo' => 'Documento de prueba', 'numero_actual' => 2]);
        foreach ([1, 2] as $n) {
            VersionDocumentoOrganizacion::create(['documento_id' => (string) $d->id, 'numero' => $n, 'clave_solicitud' => (string) Str::uuid(), 'ruta' => "organizacion/version-$n.pdf"]);
            Storage::disk('organizacion_documentos')->put("organizacion/version-$n.pdf", "versión $n");
        }
        [$exit, $report] = $this->revisar();
        $this->assertSame(0, $exit);
        $this->assertSame('sin_observaciones', $report['estado']);
        $this->assertSame(2, $report['discos']['organizacion_documentos']['referencias']);
        $this->assertSame([], $report['discos']['organizacion_documentos']['archivos_sin_referencia_observada']);
        $this->assertSame(2, VersionDocumentoOrganizacion::count());
    }

    public function test_invalid_paths_are_reported_without_probing_outside_the_private_disk(): void
    {
        SolicitudBeca::create(['folio' => 'BECA-RUTAS', 'documentos' => array_map(fn ($ruta) => ['ruta' => $ruta], ['../privado.pdf', '/tmp/privado.pdf', 'C:\\datos.pdf', "solicitud/\x1b.pdf", null])]);
        [$exit, $report] = $this->revisar();
        $this->assertSame(0, $exit);
        $this->assertCount(5, $report['discos']['becas']['referencias_invalidas']);
        $this->assertSame([], $report['discos']['becas']['referencias_sin_archivo']);
        $this->assertStringNotContainsString('../privado.pdf', json_encode($report));
    }

    public function test_a_listing_failure_is_incomplete_and_does_not_announce_empty_healthy_storage(): void
    {
        $disk = \Mockery::mock(Storage::disk('becas'));
        $disk->shouldReceive('allFiles')->once()->andThrow(new \RuntimeException('PRIVATE-ROOT-DO-NOT-PRINT'));
        Storage::shouldReceive('disk')->with('becas')->andReturn($disk);
        [$exit, $report] = $this->revisar();
        $this->assertSame(1, $exit);
        $this->assertSame('incompleta', $report['estado']);
        $this->assertSame([], $report['discos']);
        $this->assertStringNotContainsString('PRIVATE-ROOT', json_encode($report));
    }

    public function test_an_unencodable_file_name_is_incomplete_without_partial_healthy_results(): void
    {
        $organizaciones = Storage::disk('organizacion_documentos');
        $becas = \Mockery::mock(Storage::disk('becas'));
        $becas->shouldReceive('allFiles')->once()->andReturn(["solicitud/invalid-\xFF.pdf"]);
        Storage::shouldReceive('disk')->with('becas')->andReturn($becas);
        Storage::shouldReceive('disk')->with('organizacion_documentos')->andReturn($organizaciones);
        [$exit, $report] = $this->revisar();
        $this->assertSame(1, $exit);
        $this->assertSame('incompleta', $report['estado']);
        $this->assertSame([], $report['discos']);
    }

    public function test_a_malformed_document_list_is_incomplete(): void
    {
        SolicitudBeca::create(['folio' => 'BECA-METADATOS', 'documentos' => 'estructura no válida']);
        [$exit, $report] = $this->revisar();
        $this->assertSame(1, $exit);
        $this->assertSame('incompleta', $report['estado']);
        $this->assertSame([], $report['discos']);
    }
}
