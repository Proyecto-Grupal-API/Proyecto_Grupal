<?php
namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class CategoryController extends Controller
{

    public function __construct()
    {
        $this->businessId = (string) config('team4.business_id', 'BUS-CD-SOUV-001');
    }
    protected string $businessId;

    public function index()
    {
        $categories = Category::where('business_id', $this->businessId)
            ->orderBy('name')
            ->get()
            ->map(fn ($c) => [
                'id'          => (string) $c->_id,
                'name'        => (string) $c->name,
                'slug'        => (string) ($c->slug ?? ''),
                'description' => (string) ($c->description ?? ''),
                'active'      => (bool) $c->active,
            ]);

        return Inertia::render('Equipo4/Categorias', [
            'categories' => $categories,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:80',
            'description' => 'nullable|string|max:255',
            'active' => 'boolean',
            'has_sizes' => 'boolean',
            'has_colors' => 'boolean',
            'has_seasons' => 'boolean',
        ]);

        $data['business_id'] = $this->businessId;
        $data['slug']        = Str::slug($data['name']);
        $data['active']      = $data['active'] ?? true;

        Category::updateOrCreate(
            ['business_id' => $this->businessId, 'slug' => $data['slug']],
            $data
        );

        return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH));
    }

    public function update(Request $request, string $id)
    {
        $cat = Category::where('business_id', $this->businessId)->findOrFail($id);

        $data = $request->validate([
            'name'        => 'required|string|max:80',
            'description' => 'nullable|string|max:255',
            'active' => 'boolean',
            'has_sizes' => 'boolean',
            'has_colors' => 'boolean',
            'has_seasons' => 'boolean',
        ]);

        $data['slug'] = Str::slug($data['name']);
        $cat->update($data);

        return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH));
    }

    public function destroy(string $id)
    {
        $cat = Category::where('business_id', $this->businessId)->findOrFail($id);
        $cat->delete();

        return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH));
    }
}