<?php

namespace App\Http\Controllers\InventoryModule;

use App\Http\Controllers\Controller;
use App\Models\InventoryModule\Category;
use App\Services\InventoryModule\UsesBusinessContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class CategoryController extends Controller
{
    public function __construct() {}

    use UsesBusinessContext;

    public function index()
    {
        $categories = Category::where('business_id', $this->businessId())
            ->orderBy('name')
            ->get()
            ->map(fn ($c) => [
                'id' => (string) $c->_id,
                'name' => (string) $c->name,
                'slug' => (string) ($c->slug ?? ''),
                'description' => (string) ($c->description ?? ''),
                'has_sizes' => (bool) $c->has_sizes, 'has_colors' => (bool) $c->has_colors, 'has_seasons' => (bool) $c->has_seasons,
                'active' => (bool) $c->active,
            ]);

        return Inertia::render('Equipo4/Categorias', [
            'categories' => $categories,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'description' => 'nullable|string|max:255',
            'has_sizes' => 'boolean', 'has_colors' => 'boolean', 'has_seasons' => 'boolean',
            'active' => 'boolean',
        ]);

        $data['business_id'] = $this->businessId();
        $data['slug'] = Str::slug($data['name']);
        $data['active'] = $data['active'] ?? true;

        Category::updateOrCreate(
            ['business_id' => $this->businessId(), 'slug' => $data['slug']],
            $data
        );

        return redirect(parse_url(request()->headers->get('referer') ?: '/', PHP_URL_PATH));
    }

    public function update(Request $request, string $id)
    {
        $cat = Category::where('business_id', $this->businessId())->findOrFail($id);

        $data = $request->validate([
            'name' => 'required|string|max:80',
            'description' => 'nullable|string|max:255',
            'has_sizes' => 'boolean', 'has_colors' => 'boolean', 'has_seasons' => 'boolean',
            'active' => 'boolean',
        ]);

        $data['slug'] = Str::slug($data['name']);
        $cat->update($data);

        return redirect(parse_url(request()->headers->get('referer') ?: '/', PHP_URL_PATH));
    }

    public function destroy(string $id)
    {
        $cat = Category::where('business_id', $this->businessId())->findOrFail($id);
        $cat->delete();

        return redirect(parse_url(request()->headers->get('referer') ?: '/', PHP_URL_PATH));
    }
}
