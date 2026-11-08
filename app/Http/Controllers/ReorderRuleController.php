<?php

namespace App\Http\Controllers;

use App\Services\AlertGeneratorService;
use App\Services\ReorderRuleService;
use App\Services\AuditService;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MongoDB\BSON\ObjectId;

class ReorderRuleController extends Controller
{

    public function __construct()
    {
        $this->businessId = (string) config('team4.business_id', 'BUS-CD-SOUV-001');
    }
    protected string $businessId;

    public function index(Request $request): Response
    {
        return app(AlertController::class)->index($request);
    }

    public function syncAll(ReorderRuleService $rules): RedirectResponse
    {
        $before = DB::connection('mongodb')->getCollection('reorder_rules')->countDocuments(['business_id' => $this->businessId]);

        $failures = [];
        foreach (DB::connection('mongodb')->getCollection('products')->find(['business_id' => $this->businessId]) as $product) {
            $doc = (array) $product;
            try {
                $rules->syncForProduct((string) ($doc['_id'] ?? ''));
            } catch (\Throwable $e) {
                $failures[] = (string) ($doc['sku'] ?? $doc['_id'] ?? 'desconocido');
                Log::error('Fallo al sincronizar reglas de reorden.', [
                    'business_id' => $this->businessId,
                    'product_id' => (string) ($doc['_id'] ?? ''),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $after = DB::connection('mongodb')->getCollection('reorder_rules')->countDocuments(['business_id' => $this->businessId]);
        $created = max(0, $after - $before);

        if ($failures !== []) {
            return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))
                ->withErrors(['error' => 'No se pudieron sincronizar estos productos: ' . implode(', ', $failures)]);
        }

        return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))
            ->with('success', sprintf('Reglas sincronizadas: %d nuevas, %d actualizadas.', $created, $after));
    }

    public function update(Request $request, string $id, AlertGeneratorService $alerts): RedirectResponse
    {
        $validated = $request->validate([
            'reorder_point' => 'required|integer|min:0|max:1000000',
            'active' => 'required|boolean',
        ]);

        $ruleDoc = DB::connection('mongodb')->getCollection('reorder_rules')->findOne([
            '_id' => new ObjectId($id),
            'business_id' => $this->businessId,
        ]);
        if (!$ruleDoc) return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->withErrors(['error' => 'Regla no encontrada.']);
        $rule = (array) $ruleDoc;

        $productId = (string) ($rule['product_id'] ?? '');
        $locationId = (string) ($rule['location_id'] ?? '');

        DB::connection('mongodb')->getCollection('reorder_rules')->updateOne(
            ['_id' => new ObjectId($id), 'business_id' => $this->businessId],
            ['$set' => [
                'reorder_point' => (int) $validated['reorder_point'],
                'active' => (bool) $validated['active'],
                'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
            ]]
        );
        app(AuditService::class)->log('reorder_rule.updated', [
            'actor_id' => app(\App\Services\IdentityService::class)->resolveCurrentUserId(),
            'business_id' => $this->businessId,
            'entity_type' => 'ReorderRule',
            'entity_id' => $id,
            'action' => 'updated',
            'before' => [
                'reorder_point' => (int) ($rule['reorder_point'] ?? 0),
                'active' => (bool) ($rule['active'] ?? false),
            ],
            'after' => [
                'reorder_point' => (int) $validated['reorder_point'],
                'active' => (bool) $validated['active'],
            ],
        ]);

        try {
            if (!$validated['active']) {
                $alerts->resolveFor($productId, $locationId);
            } else {
                $alerts->syncOne($productId, $locationId);
            }
        } catch (\Throwable $e) {
            Log::error('No se pudo actualizar la alerta de una regla de reorden.', [
                'business_id' => $this->businessId,
                'rule_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))
                ->withErrors(['error' => 'La regla se guardó, pero no se pudo recalcular su alerta.']);
        }

        return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->with('success', 'Punto de reorden actualizado.');
    }

    public function reset(string $id, ReorderRuleService $rules, AlertGeneratorService $alerts): RedirectResponse
    {
        $ruleDoc = DB::connection('mongodb')->getCollection('reorder_rules')->findOne([
            '_id' => new ObjectId($id),
            'business_id' => $this->businessId,
        ]);
        if (!$ruleDoc) return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->withErrors(['error' => 'Regla no encontrada.']);
        $rule = (array) $ruleDoc;

        $productId = (string) ($rule['product_id'] ?? '');
        $locationId = (string) ($rule['location_id'] ?? '');

        $rules->recalculateForProduct($productId);
        app(AuditService::class)->log('reorder_rule.reset', [
            'actor_id' => app(\App\Services\IdentityService::class)->resolveCurrentUserId(),
            'business_id' => $this->businessId,
            'entity_type' => 'ReorderRule',
            'entity_id' => $id,
            'action' => 'reset',
            'before' => ['reorder_point' => (int) ($rule['reorder_point'] ?? 0)],
        ]);

        try {
            $alerts->syncOne($productId, $locationId);
        } catch (\Throwable $e) {
            Log::error('No se pudo actualizar la alerta después de reiniciar la regla de reorden.', [
                'business_id' => $this->businessId,
                'rule_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))
                ->withErrors(['error' => 'La regla se reinició, pero no se pudo recalcular su alerta.']);
        }

        return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->with('success', 'Punto de reorden recalculado.');
    }
}
