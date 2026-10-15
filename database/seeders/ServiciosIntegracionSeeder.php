<?php
namespace Database\Seeders;

use App\Models\StudentServices\Library\{Book, BookCopy};
use App\Models\StudentServices\Services\ServiceOrder;
use Illuminate\Database\Seeder;

class ServiciosIntegracionSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) { throw new \RuntimeException('Las semillas de Servicios son solo de demostración local.'); }
        $this->call([LockersSeeder::class, FacilitiesSeeder::class, RestSpacesSeeder::class, RentalAssetSeeder::class, CalendarsSeeder::class]);
        foreach ([['9780132350884', 'Código limpio', 'Robert C. Martin'], ['9780201633610', 'Patrones de diseño', 'Erich Gamma'], ['9780134494166', 'Ingeniería de software', 'Ian Sommerville']] as $i => [$isbn, $title, $author]) {
            $book = Book::firstOrCreate(['isbn' => $isbn], ['title' => $title, 'authors' => [$author], 'category' => 'Ingeniería en sistemas', 'status' => 'active']);
            foreach ([1, 2] as $copy) { BookCopy::firstOrCreate(['code' => 'E5-DEMO-'.($i + 1).'-'.$copy], ['book_id' => (string) $book->id, 'status' => 'available', 'location' => 'Biblioteca central']); }
        }
    }
}
