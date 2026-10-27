<?php

namespace App\Domains\Financial\Support;

use Illuminate\Support\Str;

/**
 * Identificador de correlación de la petición/proceso en curso. Se
 * registra en alertas e historial para enlazar una respuesta 409 con su
 * evidencia. Se registra como "scoped" (uno por petición o job).
 */
class FinancialCorrelation
{
    private ?string $id = null;

    public function set(?string $id): void
    {
        $id = $id !== null ? trim($id) : null;

        $this->id = $id !== null && $id !== ''
            ? Str::limit($id, 100, '')
            : null;
    }

    public function id(): string
    {
        return $this->id ??= (string) Str::uuid();
    }
}
