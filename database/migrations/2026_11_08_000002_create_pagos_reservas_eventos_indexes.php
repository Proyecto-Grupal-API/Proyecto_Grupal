<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $collection = DB::connection('mongodb')->getCollection('pagos_reservas_eventos');
        $collection->createIndex(['registro_id' => 1, 'generacion' => 1], ['name' => 'registro_generacion_unicos', 'unique' => true]);
        $collection->createIndex(['estado' => 1, 'revisado_en' => 1, '_id' => 1], ['name' => 'pagos_eventos_pendientes']);
    }

    public function down(): void
    {
        $collection = DB::connection('mongodb')->getCollection('pagos_reservas_eventos');
        $collection->dropIndex('registro_generacion_unicos');
        $collection->dropIndex('pagos_eventos_pendientes');
    }
};
