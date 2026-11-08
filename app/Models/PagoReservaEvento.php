<?php

namespace App\Models;

class PagoReservaEvento extends Documento
{
    protected $table = 'pagos_reservas_eventos';

    protected $guarded = ['id', '_id'];

    protected $casts = ['generacion' => 'integer', 'monto_centavos' => 'integer', 'vence_en' => 'datetime'];

    public function referencia(): string
    {
        return 'event-reservation:'.$this->registro_id.':'.$this->generacion;
    }
}
