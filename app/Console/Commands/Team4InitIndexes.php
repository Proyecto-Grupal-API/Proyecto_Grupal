<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class Team4InitIndexes extends Command
{
    protected $signature = 'team4:init-indexes';
    protected $description = 'Crea los indices requeridos por las integraciones del Equipo 4. Idempotente: no rompe si ya existen.';

    public function handle(): int
    {
        $db = DB::connection('mongodb');
        $errors = 0;

        $specs = [
            [
                'collection' => 'payment_events',
                'keys'       => ['event_id' => 1],
                'options'    => ['unique' => true, 'name' => 'uniq_payment_event_id'],
                'label'      => 'payment_events (event_id) UNIQUE',
            ],
            [
                'collection' => 'integration_idempotency',
                'keys'       => ['service' => 1, 'idempotency_key' => 1],
                'options'    => ['unique' => true, 'name' => 'uniq_service_idem'],
                'label'      => 'integration_idempotency (service, idempotency_key) UNIQUE',
            ],
            [
                'collection' => 'integration_idempotency',
                'keys'       => ['unexpired_processing_at' => 1],
                'options'    => ['expireAfterSeconds' => 0, 'name' => 'ttl_processing'],
                'label'      => 'integration_idempotency TTL unexpired_processing_at',
                'soft'       => true,
            ],
            [
                'collection' => 'payment_events',
                'keys'       => ['payment_intent_id' => 1, 'status' => 1, 'amount' => 1],
                'options'    => ['name' => 'idx_pi_status_amount'],
                'label'      => 'payment_events (payment_intent_id, status, amount)',
                'soft'       => true,
            ],
            [
                'collection' => 'team3_api_reservations',
                'keys'       => ['reservation_id' => 1],
                'options'    => ['unique' => true, 'name' => 'uniq_reservation_id'],
                'label'      => 'team3_api_reservations (reservation_id) UNIQUE',
                'soft'       => true,
            ],
            [
                'collection' => 'stock_reservations',
                'keys'       => ['idempotency_key' => 1],
                'options'    => ['name' => 'idx_stock_res_idem'],
                'label'      => 'stock_reservations (idempotency_key)',
                'soft'       => true,
            ],
        ];

        foreach ($specs as $spec) {
            $coll = $db->getCollection($spec['collection']);

            if ($this->indexKeysExist($coll, $spec['keys'])) {
                $this->info('SKIP ' . $spec['label'] . ' (ya existe)');
                continue;
            }

            try {
                $coll->createIndex($spec['keys'], $spec['options']);
                $this->info('OK   ' . $spec['label']);
            } catch (\Throwable $e) {
                $msg = $e->getMessage();

                // Mongo rechaza crear un indice con mismas keys y distinto nombre.
                // Eso significa que YA EXISTE -> tratar como SKIP.
                if (stripos($msg, 'already exists') !== false) {
                    $this->info('SKIP ' . $spec['label'] . ' (ya existe con otro nombre)');
                    continue;
                }

                if (!empty($spec['soft'])) {
                    $this->warn('WARN ' . $spec['label'] . ': ' . $msg);
                } else {
                    $this->error('FALLO ' . $spec['label'] . ': ' . $msg);
                    $errors++;
                }
            }
        }

        $this->newLine();
        if ($errors > 0) {
            $this->error("Terminado con $errors errores criticos.");
            return self::FAILURE;
        }
        $this->info('Indices listos.');
        return self::SUCCESS;
    }

    /**
     * Devuelve true si ya existe un indice con las mismas keys.
     * Usa IndexInfo::getKey() que es el API publico del driver.
     */
    private function indexKeysExist($collection, array $wantedKeys): bool
    {
        $wanted = $this->normalizeKeys($wantedKeys);

        try {
            foreach ($collection->listIndexes() as $index) {
                // Driver moderno: MongoDB\Model\IndexInfo
                $key = null;
                if (is_object($index) && method_exists($index, 'getKey')) {
                    $key = $index->getKey();
                } elseif (is_array($index) && isset($index['key'])) {
                    $key = $index['key'];
                }

                if (!is_array($key) || empty($key)) continue;

                if ($this->normalizeKeys($key) === $wanted) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // Si falla listIndexes, no bloqueamos: dejamos que createIndex decida
            return false;
        }

        return false;
    }

    private function normalizeKeys(array $keys): string
    {
        $clean = [];
        foreach ($keys as $field => $dir) {
            if ($field === '_id') continue;
            $clean[(string) $field] = (int) $dir;
        }
        ksort($clean);
        return json_encode($clean);
    }
}