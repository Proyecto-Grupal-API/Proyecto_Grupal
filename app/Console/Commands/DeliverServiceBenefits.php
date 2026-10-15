<?php
namespace App\Console\Commands;
use App\Models\AsignacionBeneficio;
use App\Services\EntregaBeneficiosServicios;
use Illuminate\Console\Command;
class DeliverServiceBenefits extends Command
{
    protected $signature = 'comunidad:entregar-beneficios';
    protected $description = 'Entrega por HTTP las becas de lockers e impresiones aprobadas al módulo 5.';
    public function handle(EntregaBeneficiosServicios $delivery): int
    {
        $count = 0;
        foreach (AsignacionBeneficio::where('estado', 'pendiente_integracion')
            ->where(fn ($q) => $q->whereNull('proximo_intento_en')->orWhere('proximo_intento_en', '<=', now()))->limit(100)->get() as $a) {
            $delivery->entregar($a); $count++;
        }
        $this->info('Beneficios revisados: '.$count.'.');
        return self::SUCCESS;
    }
}
