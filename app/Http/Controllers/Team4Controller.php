<?php

namespace App\Http\Controllers;

use App\Models\{
    Product,
    Warehouse,
    Location,
    Inventory,
    StockMovement,
    StockReservation,
    StockAlert,
    PurchaseOrder,
    Supplier,
    SupplierReturn,
    CustomerReturn
};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class Team4Controller extends Controller
{
    protected string $businessId = 'BUS-CD-SOUV-001';

    public function dashboard()
    {
        $conn = DB::connection('mongodb');

        // Conteos crudos con la coleccion directa (evita bugs de cast de Eloquent v5)
        $products = $conn->getCollection('products');
        $inventories = $conn->getCollection('inventories');
        $warehouses = $conn->getCollection('warehouses');
        $locations = $conn->getCollection('locations');
        $reservations = $conn->getCollection('stock_reservations');
        $alerts = $conn->getCollection('stock_alerts');
        $purchaseOrders = $conn->getCollection('purchase_orders');
        $suppliers = $conn->getCollection('suppliers');
        $movements = $conn->getCollection('stock_movements');
        $supplierReturns = $conn->getCollection('supplier_returns');
        $customerReturns = $conn->getCollection('customer_returns');

        $bid = $this->businessId;

        // Sums de inventario via aggregation
        $inventorySum = $inventories->aggregate([
            ['$match' => ['business_id' => $bid]],
            ['$group' => [
                '_id' => null,
                'on_hand' => ['$sum' => '$on_hand'],
                'available' => ['$sum' => '$available'],
                'reserved' => ['$sum' => '$reserved'],
            ]],
        ])->toArray();

        $sumOnHand = 0;
        $sumAvailable = 0;
        $sumReserved = 0;
        if (!empty($inventorySum)) {
            $first = (array) $inventorySum[0];
            $sumOnHand = (int) ($first['on_hand'] ?? 0);
            $sumAvailable = (int) ($first['available'] ?? 0);
            $sumReserved = (int) ($first['reserved'] ?? 0);
        }

        // Movimientos de los ultimos 7 dias
        $weekAgo = new \MongoDB\BSON\UTCDateTime((time() - 7 * 24 * 3600) * 1000);
        $monthStart = new \MongoDB\BSON\UTCDateTime(strtotime(date('Y-m-01')) * 1000);

        $stats = [
            'products' => $products->countDocuments(['business_id' => $bid]),
            'products_active' => $products->countDocuments(['business_id' => $bid, 'active' => true]),
            'warehouses' => $warehouses->countDocuments(['business_id' => $bid]),
            'locations' => $locations->countDocuments(['business_id' => $bid]),
            'inventory_units' => $sumOnHand,
            'inventory_available' => $sumAvailable,
            'inventory_reserved' => $sumReserved,
            'reservations_active' => $reservations->countDocuments(['business_id' => $bid, 'status' => 'RESERVED']),
            'alerts_active' => $alerts->countDocuments(['business_id' => $bid, 'status' => 'ACTIVE']),
            'alerts_critical' => $alerts->countDocuments([
                'business_id' => $bid,
                'status' => 'ACTIVE',
                'priority' => ['$in' => ['HIGH', 'CRITICAL']],
            ]),
            'purchase_orders_pending' => $purchaseOrders->countDocuments([
                'business_id' => $bid,
                'status' => ['$in' => ['BORRADOR', 'SOLICITADA', 'AUTORIZADA']],
            ]),
            'suppliers_active' => $suppliers->countDocuments(['business_id' => $bid, 'status' => 'ACTIVO']),
            'movements_week' => $movements->countDocuments([
                'business_id' => $bid,
                'created_at' => ['$gte' => $weekAgo],
            ]),
            'returns_month' => $supplierReturns->countDocuments([
                'business_id' => $bid,
                'created_at' => ['$gte' => $monthStart],
            ]) + $customerReturns->countDocuments([
                'business_id' => $bid,
                'created_at' => ['$gte' => $monthStart],
            ]),
        ];

        return Inertia::render('Equipo4/Dashboard', [
            'stats' => $stats,
        ]);
    }

    public function page(string $module)
    {
        $allowed = [
            'Inventario', 'Productos', 'Almacenes', 'Kardex', 'Proveedores',
            'Compras', 'Recepciones', 'Costos', 'Reservas', 'Devoluciones',
            'Alertas', 'Conteos', 'Souvenirs',
        ];

        abort_unless(in_array($module, $allowed, true), 404);

        return Inertia::render('Equipo4/' . $module);
    }

    public function products(Request $r)
    {
        $q = trim((string) $r->query('q', ''));
        $items = Product::query()
            ->when($q !== '', function ($query) use ($q) {
                $regex = '/' . preg_quote($q, '/') . '/i';
                $query->where(function ($subQuery) use ($regex) {
                    $subQuery->where('sku', 'regex', $regex)
                        ->orWhere('name', 'regex', $regex);
                });
            })
            ->limit(100)
            ->get();

        return response()->json($items);
    }

    public function warehouses()
    {
        return response()->json(Warehouse::limit(100)->get());
    }

    public function locations()
    {
        return response()->json(Location::limit(200)->get());
    }

    public function inventory()
    {
        return response()->json(Inventory::limit(500)->get());
    }

    public function movements()
    {
        return response()->json(
            StockMovement::orderBy('created_at', 'desc')->limit(200)->get()
        );
    }

    public function suppliers()
    {
        return response()->json(Supplier::limit(100)->get());
    }

    public function purchaseOrders()
    {
        return response()->json(
            PurchaseOrder::orderBy('created_at', 'desc')->limit(100)->get()
        );
    }
}
