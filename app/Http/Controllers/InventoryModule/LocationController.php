<?php

namespace App\Http\Controllers\InventoryModule;

use App\Http\Controllers\Controller;

use App\Http\Requests\InventoryModule\StoreLocationRequest;
use App\Http\Requests\InventoryModule\UpdateLocationRequest;
use App\Models\InventoryModule\Location;
use Inertia\Inertia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocationController extends Controller
{

    public function __construct()
    {
    }
    use \App\Services\InventoryModule\UsesBusinessContext;

    public function store(StoreLocationRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $validated['business_id'] = $this->businessId();

        Location::create($validated);

        return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->with('success', 'Ubicación creada exitosamente.');
    }

    public function update(UpdateLocationRequest $request, string $id): RedirectResponse
    {
        $location = Location::where('_id', $id)
            ->where('business_id', $this->businessId())
            ->first();

        if (!$location) {
            abort(404, 'Ubicación no encontrada.');
        }

        $location->update($request->validated());

        return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->with('success', 'Ubicación actualizada exitosamente.');
    }

    public function destroy(string $id): RedirectResponse
    {
        $location = Location::where('_id', $id)
            ->where('business_id', $this->businessId())
            ->first();

        if (!$location) {
            abort(404, 'Ubicación no encontrada.');
        }

        $location->delete();

        return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->with('success', 'Ubicación eliminada exitosamente.');
    }
}
