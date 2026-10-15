<?php

namespace App\Api\InventoryModule;

use App\Http\Controllers\Controller;
use App\Models\InventoryModule\InventoryBusiness;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MarketplaceBridgeController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate(['business_id' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:120']]);
        $business = InventoryBusiness::firstOrCreate(['business_id' => $data['business_id']], ['name' => $data['name'], 'active' => true]);
        abort_unless($business->active, 409, 'El inventario de este negocio está inactivo.');

        return response()->json(['business_id' => $business->business_id, 'active' => true], $business->wasRecentlyCreated ? 201 : 200);
    }

    public function operation(Request $request, string $businessId)
    {
        $data = $request->validate(['idempotency_key' => ['required', 'string', 'min:8', 'max:128'], 'order_id' => ['required', 'string', 'max:100']]);
        $db = DB::connection('inventory');
        $row = $db->getCollection('integration_idempotency')->findOne(['service' => 'team3.reserve:'.$businessId, 'idempotency_key' => $data['idempotency_key']]);
        if (! $row) {
            return response()->json(['state' => 'not_found']);
        }
        if (($row['status'] ?? '') !== 'COMPLETED') {
            return response()->json(['state' => strtolower($row['status'] ?? 'unknown')]);
        }
        $result = (array) ($row['result'] ?? []);
        $reservation = $db->getCollection('team3_api_reservations')->findOne(['reservation_id' => $result['reservation_id'] ?? '',
            'business_id' => $businessId, 'order_id' => $data['order_id']]);
        if (! $reservation) {
            return response()->json(['state' => 'not_found']);
        }

        return response()->json(['state' => 'completed', 'reservation_id' => $reservation['reservation_id'],
            'status' => $reservation['status'], 'release_reason' => $reservation['release_reason'] ?? null]);
    }
}
