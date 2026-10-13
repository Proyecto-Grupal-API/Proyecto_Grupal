<?php

use App\Services\StudentServices\Payments\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use MongoDB\Laravel\Connection;
use MongoDB\Model\BSONDocument;

/**
 * Lockers guardaban costos en pesos (Decimal128). El resto del Equipo 5 y el
 * ledger del Equipo 2 usan centavos enteros, así que se convierten:
 * locker_periods.prices → prices_cents y locker_requests.amount → amount_cents.
 * Es idempotente: solo toca documentos que aún tienen el campo anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'mongodb') {
            return;
        }

        $database = $this->mongo();

        $periods = $database->getCollection('locker_periods');

        // Un periodo editado después del cambio ya tiene prices_cents (lo
        // vigente); solo se quita el campo viejo para no pisarlo.
        $periods->updateMany(
            ['prices' => ['$exists' => true], 'prices_cents' => ['$exists' => true]],
            ['$unset' => ['prices' => '']],
        );

        foreach ($periods->find(['prices' => ['$exists' => true], 'prices_cents' => ['$exists' => false]]) as $period) {
            if (! $period instanceof BSONDocument) {
                continue;
            }

            $cents = [];

            foreach ((array) $period['prices'] as $size => $pesos) {
                if ($pesos !== null) {
                    $cents[$size] = Money::toCents($pesos);
                }
            }

            $periods->updateOne(
                ['_id' => $period['_id']],
                ['$set' => ['prices_cents' => $cents], '$unset' => ['prices' => '']],
            );
        }

        $requests = $database->getCollection('locker_requests');

        $requests->updateMany(
            ['amount' => ['$exists' => true], 'amount_cents' => ['$exists' => true]],
            ['$unset' => ['amount' => '']],
        );

        foreach ($requests->find(['amount' => ['$exists' => true], 'amount_cents' => ['$exists' => false]]) as $request) {
            if (! $request instanceof BSONDocument) {
                continue;
            }

            $requests->updateOne(
                ['_id' => $request['_id']],
                [
                    '$set' => ['amount_cents' => $request['amount'] === null ? null : Money::toCents($request['amount'])],
                    '$unset' => ['amount' => ''],
                ],
            );
        }
    }

    public function down(): void
    {
        // Sin reversa: volver a Decimal128 reintroduciría importes en float.
    }

    private function mongo(): Connection
    {
        $connection = DB::connection('mongodb');

        if (! $connection instanceof Connection) {
            throw new RuntimeException('La conexión [mongodb] no usa el driver de MongoDB.');
        }

        return $connection;
    }
};
