<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Category;
use Illuminate\Support\Str;

class EnsureSouvenirCategory extends Command
{
    protected $signature = 'team4:ensure-souvenir-category';
    protected $description = 'Crea la categoría Souvenirs si no existe, con tallas/colores/temporadas activos';

    public function handle()
    {
        $businessId = config('team4.business_id', 'BUS-CD-SOUV-001');
        $slug = 'souvenirs';
        
        $category = Category::where('business_id', $businessId)->where('slug', $slug)->first();
        
        if (!$category) {
            Category::create([
                'business_id' => $businessId,
                'name' => 'Souvenirs',
                'slug' => $slug,
                'description' => 'Productos promocionales del campus',
                'active' => true,
                'has_sizes' => true,
                'has_colors' => true,
                'has_seasons' => true,
            ]);
            $this->info('✅ Categoría "Souvenirs" creada con tallas, colores y temporadas activos.');
        } else {
            $this->info('ℹ️ La categoría "Souvenirs" ya existe. Actualizando flags...');
            $category->update([
                'has_sizes' => true,
                'has_colors' => true,
                'has_seasons' => true,
            ]);
            $this->info('✅ Flags de la categoría "Souvenirs" actualizados.');
        }
        
        return 0;
    }
}