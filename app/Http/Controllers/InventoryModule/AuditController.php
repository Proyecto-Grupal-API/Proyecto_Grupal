<?php

namespace App\Http\Controllers\InventoryModule;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use MongoDB\BSON\UTCDateTime;

/** Consulta de la bitácora de Inventarios del negocio seleccionado. Sólo lectura. */
class AuditController extends Controller
{
    use \App\Services\InventoryModule\UsesBusinessContext;

    private const PER_PAGE = 50;

    /** Familias de eventos que ofrece el filtro; la clave es el prefijo guardado en `event`. */
    public const FAMILIES = [
        'Product' => 'Productos',
        'Category' => 'Categorías',
        'Warehouse' => 'Almacenes',
        'Location' => 'Ubicaciones',
        'Supplier' => 'Proveedores',
        'PurchaseOrder' => 'Órdenes de compra',
        'GoodsReceipt' => 'Recepciones',
        'SupplierReturn' => 'Devoluciones a proveedor',
        'CustomerReturn' => 'Devoluciones de cliente',
        'Inventory' => 'Existencias y transferencias',
        'StockMovement' => 'Movimientos de kardex',
        'StockCount' => 'Conteos',
        'StockReservation' => 'Reservas',
        'StockAlert' => 'Alertas',
        'ReorderRule' => 'Reglas de reorden',
        'CostHistory' => 'Costos',
    ];

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'event' => 'nullable|string|in:'.implode(',', array_keys(self::FAMILIES)),
            'q' => 'nullable|string|max:80',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'page' => 'nullable|integer|min:1|max:10000',
        ]);

        $filter = ['business_id' => $this->businessId()];
        if (! empty($validated['event'])) {
            $filter['event'] = ['$regex' => '^'.preg_quote($validated['event'], '/').'\\.'];
        }
        $q = trim((string) ($validated['q'] ?? ''));
        if ($q !== '') {
            $pattern = ['$regex' => preg_quote($q, '/'), '$options' => 'i'];
            $filter['$or'] = [['entity_label' => $pattern], ['actor_name' => $pattern], ['after.folio' => $pattern],
                ['after.sku' => $pattern], ['after.name' => $pattern], ['after.code' => $pattern], ['entity_id' => $q], ['actor_id' => $q]];
        }
        $range = [];
        if (! empty($validated['from'])) {
            $range['$gte'] = new UTCDateTime(Carbon::parse($validated['from'])->startOfDay());
        }
        if (! empty($validated['to'])) {
            $range['$lte'] = new UTCDateTime(Carbon::parse($validated['to'])->endOfDay());
        }
        if ($range !== []) {
            $filter['created_at'] = $range;
        }

        $collection = DB::connection('inventory')->getCollection('audit_events');
        $total = $collection->countDocuments($filter);
        $page = (int) ($validated['page'] ?? 1);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $lastPage);

        $events = [];
        foreach ($collection->find($filter, ['sort' => ['created_at' => -1, '_id' => -1],
            'skip' => ($page - 1) * self::PER_PAGE, 'limit' => self::PER_PAGE]) as $doc) {
            $after = $this->plain($doc['after'] ?? null);
            $before = $this->plain($doc['before'] ?? null);
            $event = (string) ($doc['event'] ?? '');
            $events[] = [
                '_id' => (string) $doc['_id'],
                'event' => $event,
                'family' => self::FAMILIES[strstr($event, '.', true) ?: $event] ?? (strstr($event, '.', true) ?: $event),
                'action' => (string) ($doc['action'] ?? ''),
                'entity_id' => (string) ($doc['entity_id'] ?? ''),
                'entity_label' => (string) ($doc['entity_label'] ?? $after['folio'] ?? $after['sku'] ?? $after['code'] ?? $after['name']
                    ?? $before['folio'] ?? $before['sku'] ?? $before['code'] ?? $before['name'] ?? ''),
                'actor_id' => (string) ($doc['actor_id'] ?? ''),
                'actor_name' => (string) ($doc['actor_name'] ?? ''),
                'ip' => (string) ($doc['ip'] ?? ''),
                'correlation_id' => (string) ($doc['correlation_id'] ?? ''),
                'created_at' => isset($doc['created_at']) && $doc['created_at'] instanceof UTCDateTime
                    ? Carbon::instance($doc['created_at']->toDateTime())->toIso8601String() : null,
                'changes' => $this->changes($before, $after),
            ];
        }

        $base = ['business_id' => $this->businessId()];
        $today = new UTCDateTime(now()->startOfDay());

        return Inertia::render('Equipo4/Auditoria', [
            'events' => $events,
            'families' => self::FAMILIES,
            'kpis' => [
                ['label' => 'Eventos registrados', 'value' => $collection->countDocuments($base)],
                ['label' => 'Hoy', 'value' => $collection->countDocuments([...$base, 'created_at' => ['$gte' => $today]]), 'color' => 'success'],
                ['label' => 'Coinciden con el filtro', 'value' => $total],
            ],
            'pagination' => ['current_page' => $page, 'last_page' => $lastPage, 'per_page' => self::PER_PAGE, 'total' => $total,
                'from' => $total > 0 ? ($page - 1) * self::PER_PAGE + 1 : 0, 'to' => min($page * self::PER_PAGE, $total)],
            'filters' => ['event' => $validated['event'] ?? '', 'q' => $q, 'from' => $validated['from'] ?? '', 'to' => $validated['to'] ?? ''],
        ]);
    }

    /** Documento BSON a arreglo plano de escalares legibles. */
    private function plain(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        $out = [];
        foreach ((array) json_decode(json_encode($value), true) as $key => $item) {
            if (in_array($key, ['_id', 'business_id', 'created_at', 'updated_at'], true)) {
                continue;
            }
            if (is_array($item)) {
                $item = isset($item['$date']) ? (is_array($item['$date']) ? ($item['$date']['$numberLong'] ?? '') : $item['$date'])
                    : (isset($item['$oid']) ? $item['$oid'] : json_encode($item, JSON_UNESCAPED_UNICODE));
            }
            $out[(string) $key] = is_bool($item) ? ($item ? 'sí' : 'no') : (string) ($item ?? '');
        }

        return $out;
    }

    /** Sólo lo que cambió (o todo lo nuevo, en un alta). */
    private function changes(array $before, array $after): array
    {
        $rows = [];
        foreach ($after as $key => $value) {
            if (! array_key_exists($key, $before) || $before[$key] !== $value) {
                $rows[] = ['field' => $key, 'before' => $before[$key] ?? null, 'after' => $value];
            }
        }
        if ($after === []) {
            foreach ($before as $key => $value) {
                $rows[] = ['field' => $key, 'before' => $value, 'after' => null];
            }
        }

        return array_slice($rows, 0, 12);
    }
}
