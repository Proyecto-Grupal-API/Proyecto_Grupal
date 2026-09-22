<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLocationRequest;
use App\Http\Requests\UpdateLocationRequest;
use App\Models\Location;
use Inertia\Inertia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    protected string $businessId = 'BUS-CD-SOUV-001';

    public function store(StoreLocationRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $validated['business_id'] = $this->businessId;

        Location::create($validated);

        return redirect()->back()->with('success', 'Ubicación creada exitosamente.');
    }

    public function update(UpdateLocationRequest $request, string $id): RedirectResponse
    {
        $location = Location::where('_id', $id)
            ->where('business_id', $this->businessId)
            ->first();

        if (!$location) {
            abort(404, 'Ubicación no encontrada.');
        }

        $location->update($request->validated());

        return redirect()->back()->with('success', 'Ubicación actualizada exitosamente.');
    }

    public function destroy(string $id): RedirectResponse
    {
        $location = Location::where('_id', $id)
            ->where('business_id', $this->businessId)
            ->first();

        if (!$location) {
            abort(404, 'Ubicación no encontrada.');
        }

        $location->delete();

        return redirect()->back()->with('success', 'Ubicación eliminada exitosamente.');
    }
}
