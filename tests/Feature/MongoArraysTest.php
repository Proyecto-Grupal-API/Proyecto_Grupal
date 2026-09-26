<?php

namespace Tests\Feature;

use App\Models\AsignacionBeneficio;
use App\Models\Campana;
use App\Models\ConvocatoriaBeca;
use App\Models\SolicitudBeca;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use RuntimeException;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

class MongoArraysTest extends TestCase
{
    use RefreshMongoDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_09_22_000000_normalize_becas_campanas_arrays.php');
    }

    private function casos(): array
    {
        return [[new ConvocatoriaBeca, ['beneficio' => ['slug' => 'bono'], 'requisitos_documentos' => ['Constancia']]], [new SolicitudBeca, ['documentos' => [['nombre' => 'Constancia', 'ruta' => 'privada.pdf']], 'dictamen' => ['motivo' => 'Aprobado']]], [new AsignacionBeneficio, ['contrato' => ['version' => 1, 'cantidad' => 2]]], [new Campana, ['destinatarios' => ['u1', 'u2']]]];
    }

    public function test_new_writes_are_native_and_support_nested_queries(): void
    {
        foreach ($this->casos() as [$model, $fields]) {
            $model->fill($fields)->save();
            $raw = DB::connection('mongodb')->getDatabase()->selectCollection($model->getTable())->findOne(['_id' => new ObjectId((string) $model->id)], ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);
            foreach ($fields as $field => $expected) {
                $this->assertIsArray($raw[$field]);
                $this->assertSame($expected, $model->fresh()->{$field});
            }
        }
        $this->assertSame(1, ConvocatoriaBeca::where('beneficio.slug', 'bono')->count());
        $this->assertSame(1, AsignacionBeneficio::where('contrato.version', 1)->count());
    }

    public function test_migration_preserves_legacy_contents_is_repeatable_and_keeps_nulls(): void
    {
        foreach ($this->casos() as [$model, $fields]) {
            $c = DB::connection('mongodb')->getDatabase()->selectCollection($model->getTable());
            $id = $c->insertOne(array_map(fn ($v) => json_encode($v), $fields))->getInsertedId();
            $this->migration()->up();
            $this->migration()->up();
            foreach ($fields as $field => $expected) {
                $this->assertSame($expected, $model->newQuery()->findOrFail((string) $id)->{$field});
            }
        }
        $c = DB::connection('mongodb')->getDatabase()->selectCollection('convocatorias_becas');
        $id = $c->insertOne(['beneficio' => null, 'requisitos_documentos' => []])->getInsertedId();
        $this->migration()->up();
        $this->assertNull($c->findOne(['_id' => $id])['beneficio']);
        $this->assertSame([], ConvocatoriaBeca::find((string) $id)->requisitos_documentos);
    }

    public function test_invalid_json_aborts_before_any_changes_and_keeps_original_value(): void
    {
        $db = DB::connection('mongodb')->getDatabase();
        $id = $db->selectCollection('convocatorias_becas')->insertOne(['beneficio' => '{"slug":"bono"}'])->getInsertedId();
        $bad = $db->selectCollection('campañas')->insertOne(['destinatarios' => '[broken'])->getInsertedId();
        try {
            $this->migration()->up();
            $this->fail('Debió rechazar JSON inválido.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('campañas.destinatarios', $e->getMessage());
        }
        $this->assertSame('{"slug":"bono"}', $db->selectCollection('convocatorias_becas')->findOne(['_id' => $id])['beneficio']);
        $this->assertSame('[broken', $db->selectCollection('campañas')->findOne(['_id' => $bad])['destinatarios']);
    }
}
