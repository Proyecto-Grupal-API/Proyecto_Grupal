<?php

namespace App\Api\InventoryModule;

use App\Services\InventoryModule\BusinessContext;
use App\Services\InventoryModule\HttpEventDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\BulkWriteException;

/** Bandeja de recepción: los manejadores de negocio E7 siguen pendientes de acuerdo. */
class Team7WebhookController
{
    public function receive(Request $request)
    {
        abort_unless(config('team4.integration.e7_inbox_enabled') === true, 503);
        abort_unless(strlen($request->getContent()) <= 65536, 413);
        $d = $request->validate([
            'event_id' => 'required|string|max:100', 'event_type' => ['required', Rule::in(HttpEventDispatcher::EVENTS)],
            'source_domain' => 'required|string|max:100', 'occurred_at' => 'required|date',
            'payload' => 'required|array', 'payload.business_id' => 'required|string|max:100',
        ]);
        $d['payload'] = $request->input('payload');
        $business = app(BusinessContext::class)->id();
        abort_unless($d['payload']['business_id'] === $business, 403);
        $sort = function (array $value) use (&$sort): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as $key => &$entry) {
                abort_if(is_string($key) && in_array(strtolower($key), ['name', 'nombre', 'email', 'correo', 'password', 'token', 'secret', 'documento', 'expediente'], true), 422);
                if (is_array($entry)) {
                    $entry = $sort($entry);
                }
            }

            return $value;
        };
        $hash = hash('sha256', json_encode($sort($d), JSON_THROW_ON_ERROR));
        $coll = DB::connection('inventory')->getCollection('integration_inbox');
        $key = ['business_id' => $business, 'source_system' => 'E7', 'event_id' => $d['event_id']];
        try {
            $result = $coll->updateOne($key, ['$setOnInsert' => $d + $key + ['request_hash' => $hash,
                'received_at' => new UTCDateTime(now()), 'status' => 'RECEIVED']], ['upsert' => true]);
            $created = $result->getUpsertedCount() === 1;
        } catch (BulkWriteException $e) {
            if ($e->getCode() !== 11000) {
                throw $e;
            }
            $created = false;
        }
        $stored = $coll->findOne($key);
        abort_unless($stored && hash_equals((string) ($stored['request_hash'] ?? ''), $hash), 409);

        return response()->json(['status' => $created ? 'received' : 'already_received', 'event_id' => $d['event_id']], $created ? 202 : 200);
    }
}
