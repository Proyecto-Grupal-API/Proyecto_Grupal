<?php

namespace Tests\Feature;

use App\Models\AuditoriaComunidad;
use App\Models\DocumentoOrganizacion;
use App\Models\MiembroOrganizacion;
use App\Models\User;
use App\Models\VersionDocumentoOrganizacion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

class DocumentosOrganizacionTest extends TestCase
{
    use RefreshMongoDatabase;

    private User $presidencia;

    private User $persona;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('organizacion_documentos');
        $this->presidencia = User::where('email', 'presidencia@campus.test')->firstOrFail();
        $this->persona = User::where('email', 'estudiante@campus.test')->firstOrFail();
    }

    private function archivo(string $contenido = 'Acta inicial'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('acta.pdf', "%PDF-1.4\n$contenido\n%%EOF");
    }

    private function datos(array $extra = []): array
    {
        return [...['titulo' => 'Acta constitutiva', 'categoria' => 'acta', 'descripcion' => 'Documento institucional de la organización.', 'fecha_documento' => today()->toDateString(), 'visibilidad' => 'presidencia', 'clave_solicitud' => (string) Str::uuid()], ...$extra];
    }

    private function crear(array $extra = []): array
    {
        return $this->actingAs($this->presidencia)->postJson('/api/organizaciones/documentos', [...$this->datos($extra), 'archivo' => $this->archivo()])->assertCreated()->json('documento');
    }

    public function test_default_document_is_private_and_paths_are_not_exposed(): void
    {
        $this->getJson('/api/organizaciones/documentos')->assertUnauthorized();
        $datos = $this->datos();
        unset($datos['visibilidad']);
        $d = $this->actingAs($this->presidencia)->postJson('/api/organizaciones/documentos', [...$datos, 'archivo' => $this->archivo()])->assertCreated()->json('documento');
        $this->assertSame('presidencia', $d['visibilidad']);
        $this->assertArrayNotHasKey('ruta', $d['version_actual']);
        $this->get('/api/organizaciones/documentos/'.$d['id'].'/descargar')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(1, AuditoriaComunidad::where('accion', 'organizacion_documento_descargado')->count());
        $this->actingAs($this->persona)->getJson('/api/organizaciones/documentos')->assertJsonCount(0, 'documentos');
        $this->getJson('/api/organizaciones/documentos/'.$d['id'].'/versiones')->assertNotFound();
        $this->getJson('/api/organizaciones/documentos/'.$d['id'].'/descargar')->assertNotFound();
        $this->actingAs(User::where('email', 'consejo@campus.test')->firstOrFail())->getJson('/api/organizaciones/documentos/'.$d['id'].'/descargar')->assertNotFound();
    }

    public function test_upload_types_extensions_size_and_management_permissions_are_enforced(): void
    {
        $this->actingAs($this->presidencia);
        foreach ([UploadedFile::fake()->create('peligro.php', 10, 'application/pdf'), UploadedFile::fake()->create('grande.pdf', 5121, 'application/pdf'), UploadedFile::fake()->create('imagen.svg', 1, 'image/svg+xml')] as $archivo) {
            $this->postJson('/api/organizaciones/documentos', [...$this->datos(), 'archivo' => $archivo])->assertUnprocessable();
        }
        $this->actingAs($this->persona)->postJson('/api/organizaciones/documentos', [...$this->datos(), 'archivo' => $this->archivo()])->assertForbidden();
        $this->assertSame([], Storage::disk('organizacion_documentos')->allFiles());
    }

    public function test_create_retries_are_bound_to_metadata_actor_and_file_content(): void
    {
        $datos = $this->datos();
        $id = $this->actingAs($this->presidencia)->postJson('/api/organizaciones/documentos', [...$datos, 'archivo' => $this->archivo()])->assertCreated()->json('documento.id');
        $this->postJson('/api/organizaciones/documentos', [...$datos, 'archivo' => $this->archivo()])->assertOk()->assertJsonPath('documento.id', $id);
        $this->postJson('/api/organizaciones/documentos', [...$datos, 'archivo' => $this->archivo('Contenido diferente')])->assertConflict();
        $this->postJson('/api/organizaciones/documentos', [...$datos, 'titulo' => 'Otro título', 'archivo' => $this->archivo()])->assertConflict();
        $this->assertSame(1, DocumentoOrganizacion::count());
        $this->assertSame(1, VersionDocumentoOrganizacion::count());
        $this->assertCount(1, Storage::disk('organizacion_documentos')->allFiles());
    }

    public function test_versions_are_immutable_and_sharing_does_not_reveal_previous_private_versions(): void
    {
        $d = $this->crear();
        $id = $d['id'];
        $anterior = $d['version_actual']['id'];
        $cambio = ['version' => $d['version'], 'motivo' => 'Nueva acta aprobada por la organización.', 'clave_solicitud' => (string) Str::uuid()];
        $actual = $this->postJson("/api/organizaciones/documentos/$id/versiones", [...$cambio, 'archivo' => $this->archivo('Segunda versión')])->assertCreated()->json('documento');
        $this->postJson("/api/organizaciones/documentos/$id/versiones", [...$cambio, 'archivo' => $this->archivo('Segunda versión')])->assertOk()->assertJsonPath('documento.version', $actual['version']);
        $this->postJson("/api/organizaciones/documentos/$id/versiones", [...$cambio, 'archivo' => $this->archivo('Tercera versión diferente')])->assertConflict();
        $this->putJson("/api/organizaciones/documentos/$id", [...$this->datos(), 'version' => $actual['version'], 'visibilidad' => 'integrantes'])->assertOk();
        $this->getJson("/api/organizaciones/documentos/$id/versiones")->assertJsonCount(2, 'versiones');
        $this->actingAs($this->persona)->getJson('/api/organizaciones/documentos')->assertJsonCount(1, 'documentos');
        $this->getJson("/api/organizaciones/documentos/$id/versiones")->assertJsonCount(1, 'versiones')->assertJsonPath('versiones.0.numero', 2);
        $this->get("/api/organizaciones/documentos/$id/descargar")->assertOk();
        $this->getJson("/api/organizaciones/documentos/$id/versiones/$anterior/descargar")->assertNotFound();
        $this->actingAs($this->presidencia)->get("/api/organizaciones/documentos/$id/versiones/$anterior/descargar")->assertOk();
        $this->assertCount(2, Storage::disk('organizacion_documentos')->allFiles());
    }

    public function test_stale_metadata_changes_are_rejected_and_withdrawal_keeps_private_history(): void
    {
        $d = $this->crear(['visibilidad' => 'integrantes']);
        $id = $d['id'];
        $this->putJson("/api/organizaciones/documentos/$id", [...$this->datos(), 'version' => 1])->assertOk()->assertJsonPath('documento.version', 2);
        $this->putJson("/api/organizaciones/documentos/$id", [...$this->datos(), 'version' => 1])->assertConflict();
        $this->postJson("/api/organizaciones/documentos/$id/retirar", ['version' => 2, 'motivo' => 'Se retira por actualización institucional.'])->assertOk();
        $this->postJson("/api/organizaciones/documentos/$id/retirar", ['version' => 2, 'motivo' => 'Reintento del retiro previamente autorizado.'])->assertOk();
        $this->assertSame(1, AuditoriaComunidad::where('accion', 'organizacion_documento_retirado')->count());
        $this->getJson('/api/organizaciones/documentos?estado=retirado')->assertJsonCount(1, 'documentos');
        $this->get("/api/organizaciones/documentos/$id/descargar")->assertOk();
        $this->postJson("/api/organizaciones/documentos/$id/versiones", ['version' => 3, 'motivo' => 'Intento de cambiar un documento retirado.', 'clave_solicitud' => (string) Str::uuid(), 'archivo' => $this->archivo('Otra versión')])->assertUnprocessable();
        $this->actingAs($this->persona)->getJson("/api/organizaciones/documentos/$id/descargar")->assertNotFound();
        $this->getJson('/api/organizaciones/documentos?estado=retirado')->assertJsonCount(0, 'documentos');
        $this->assertCount(1, Storage::disk('organizacion_documentos')->allFiles());
    }

    public function test_database_failure_rolls_back_document_audit_and_stored_file(): void
    {
        $fallar = true;
        VersionDocumentoOrganizacion::creating(function () use (&$fallar) {
            if ($fallar) {
                $fallar = false;
                throw new \RuntimeException('Fallo de persistencia');
            }
        });
        $this->withoutExceptionHandling();
        $datos = $this->datos();
        try {
            $this->actingAs($this->presidencia)->postJson('/api/organizaciones/documentos', [...$datos, 'archivo' => $this->archivo()]);
            $this->fail('Debe propagarse el fallo.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Fallo de persistencia', $e->getMessage());
        }
        $this->assertSame(0, DocumentoOrganizacion::count());
        $this->assertSame(0, VersionDocumentoOrganizacion::count());
        $this->assertSame(0, AuditoriaComunidad::where('accion', 'organizacion_documento_creado')->count());
        $this->assertSame([], Storage::disk('organizacion_documentos')->allFiles());
        $this->postJson('/api/organizaciones/documentos', [...$datos, 'archivo' => $this->archivo()])->assertCreated();
    }

    public function test_stale_or_failed_new_version_preserves_the_current_file_and_metadata(): void
    {
        $d = $this->crear();
        $id = $d['id'];
        $datos = ['version' => '1', 'motivo' => 'Se actualiza el acta de la organización.', 'clave_solicitud' => (string) Str::uuid()];
        $this->post('/api/organizaciones/documentos/'.$id.'/versiones', [...$datos, 'archivo' => $this->archivo('Acta segunda')], ['Accept' => 'application/json'])->assertCreated();
        $this->post('/api/organizaciones/documentos/'.$id.'/versiones', [...$datos, 'archivo' => $this->archivo('Acta segunda')], ['Accept' => 'application/json'])->assertOk();
        $this->postJson('/api/organizaciones/documentos/'.$id.'/versiones', [...$datos, 'clave_solicitud' => (string) Str::uuid(), 'archivo' => $this->archivo('Intento desactualizado')])->assertConflict();
        $actual = DocumentoOrganizacion::findOrFail($id)->version_actual_id;
        $fallar = true;
        VersionDocumentoOrganizacion::creating(function () use (&$fallar) {
            if ($fallar) {
                $fallar = false;
                throw new \RuntimeException('Fallo de nueva versión');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->postJson('/api/organizaciones/documentos/'.$id.'/versiones', [...$datos, 'version' => 2, 'clave_solicitud' => (string) Str::uuid(), 'archivo' => $this->archivo('Archivo que falla')]);
            $this->fail('Debe propagarse el fallo de persistencia.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Fallo de nueva versión', $e->getMessage());
        }
        $this->assertSame($actual, DocumentoOrganizacion::findOrFail($id)->version_actual_id);
        $this->assertSame(2, DocumentoOrganizacion::findOrFail($id)->version);
        $this->assertSame(2, VersionDocumentoOrganizacion::count());
        $this->assertCount(2, Storage::disk('organizacion_documentos')->allFiles());
        $this->assertSame(1, AuditoriaComunidad::where('accion', 'organizacion_documento_version')->count());
        $this->get('/api/organizaciones/documentos/'.$id.'/descargar')->assertOk();
    }

    public function test_losing_membership_removes_access_to_shared_documents(): void
    {
        $d = $this->crear(['visibilidad' => 'integrantes']);
        $url = '/api/organizaciones/documentos/'.$d['id'].'/descargar';
        $this->actingAs($this->persona)->get($url)->assertOk();
        MiembroOrganizacion::where('usuario_id', (string) $this->persona->id)->update(['estado' => 'inactivo', 'eliminado_en' => now()]);
        $this->getJson($url)->assertForbidden();
        $this->getJson('/api/organizaciones/documentos')->assertForbidden();
    }
}
