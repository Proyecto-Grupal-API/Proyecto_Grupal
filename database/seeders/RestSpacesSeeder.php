<?php

namespace Database\Seeders;

use App\Models\StudentServices\RestSpaces\RestSpace;
use Illuminate\Database\Seeder;

/**
 * Modulo 5.6 - Catálogo inicial de zonas de descanso.
 */
class RestSpacesSeeder extends Seeder
{
    public function run(): void
    {
        $spaces = [
            ['code' => 'ZD-CAP-001', 'name' => 'Cápsula de descanso 1', 'type' => 'capsule', 'location' => 'Biblioteca · Planta baja', 'capacity' => 1, 'description' => 'Cápsula individual para descanso breve y recuperación.'],
            ['code' => 'ZD-CAP-002', 'name' => 'Cápsula de descanso 2', 'type' => 'capsule', 'location' => 'Biblioteca · Planta baja', 'capacity' => 1, 'description' => 'Cápsula individual con espacio de descanso.'],
            ['code' => 'ZD-SIL-001', 'name' => 'Espacio silencioso 1', 'type' => 'silent', 'location' => 'Edificio A · Piso 2', 'capacity' => 1, 'description' => 'Área individual destinada al descanso o concentración.'],
            ['code' => 'ZD-SIL-002', 'name' => 'Espacio silencioso 2', 'type' => 'silent', 'location' => 'Edificio A · Piso 2', 'capacity' => 1, 'description' => 'Zona silenciosa con iluminación tenue.'],
            ['code' => 'ZD-SIL-003', 'name' => 'Espacio silencioso 3', 'type' => 'silent', 'location' => 'Edificio B · Piso 1', 'capacity' => 2, 'description' => 'Espacio de descanso compartido para máximo dos personas.'],
            ['code' => 'ZD-SIL-004', 'name' => 'Espacio silencioso 4', 'type' => 'silent', 'location' => 'Edificio B · Piso 1', 'capacity' => 1, 'description' => 'Área tranquila alejada de zonas de tránsito.'],
            ['code' => 'ZD-SIL-005', 'name' => 'Espacio silencioso 5', 'type' => 'silent', 'location' => 'Biblioteca · Piso 1', 'capacity' => 1, 'description' => 'Área silenciosa cercana a la zona de lectura.'],
            ['code' => 'ZD-CHR-001', 'name' => 'Sillón de descanso 1', 'type' => 'chair', 'location' => 'Centro estudiantil', 'capacity' => 1, 'description' => 'Sillón individual para descansos cortos.'],
            ['code' => 'ZD-CHR-002', 'name' => 'Sillón de descanso 2', 'type' => 'chair', 'location' => 'Centro estudiantil', 'capacity' => 1, 'description' => 'Sillón individual en zona de descanso.'],
        ];

        foreach ($spaces as $space) {
            RestSpace::query()->firstOrCreate(
                ['code' => $space['code']],
                [...$space, 'status' => 'available', 'active' => true]
            );
        }
    }
}
