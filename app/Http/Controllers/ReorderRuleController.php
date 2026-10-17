<?php

namespace App\Http\Controllers;

use App\Services\AlertGeneratorService;
use App\Services\ReorderRuleService;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        foreach (DB::connection('mongodb')->getCollection('products')->find(['business_id' => $this->businessId]) as $product) {
            try {
                $doc = (array) $product;
                $rules->syncForProduct((string) ($doc['_id'] ?? ''));
            } catch (\Throwable $e) {}
        }

        $after = DB::connection('mongodb')->getCollection('reorder_rules')->countDocuments(['business_id' => $this->businessId]);
        $created = max(0, $after - $before);

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
            ['_id' => new ObjectId($id)],
            ['$set' => [
                'reorder_point' => (int) $validated['reorder_point'],
                'active' => (bool) $validated['active'],
                'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
            ]]
        );

        try {
            if (!$validated['active']) {
                $alerts->resolveFor($productId, $locationId);
            } else {
                $alerts->syncOne($productId, $locationId);
            }
        } catch (\Throwable $e) {}

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

        try {
            $alerts->syncOne($productId, $locationId);
        } catch (\Throwable $e) {}

        return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->with('success', 'Punto de reorden recalculado.');
    }
}
