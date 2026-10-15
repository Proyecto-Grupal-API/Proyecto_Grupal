<?php
namespace App\Http\Controllers\InventoryModule;

use App\Http\Controllers\Controller;

use App\Http\Requests\InventoryModule\ReserveStockRequest;
use App\Services\InventoryModule\IntegrationContractService;
use App\Services\InventoryModule\ReturnService;
use App\Http\Requests\InventoryModule\ReturnRequest;
use Illuminate\Http\Request;

class IntegrationController extends Controller
{
    public function reserve(ReserveStockRequest $request, IntegrationContractService $service)
    {
        return response()->json([
            'data' => $service->reserveStock($request->validated())
        ], 201);
    }

    public function supplierReturn(ReturnRequest $request, ReturnService $service)
    {
        return response()->json(['data'=>$service->supplier($request->validated())], 201);
    }

    public function customerReturn(ReturnRequest $request, ReturnService $service)
    {
        return response()->json(['data'=>$service->customer($request->validated())], 201);
    }

    public function availability(Request $request)
    {
        $request->validate([
            'product_id'=>'required|string|max:64',
            'location_id'=>'nullable|string|max:64'
        ]);
        $query = \App\Models\InventoryModule\Inventory::where('product_id',$request->string('product_id'));
        if ($request->filled('location_id')) $query->where('location_id',$request->string('location_id'));
        $total = (int)$query->sum('available');
        return response()->json(['product_id'=>$request->string('product_id'),'available'=>$total]);
    }
}
