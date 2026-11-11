<?php
namespace App\Console\Commands;
use App\Models\AsignacionBeneficio;
use App\Services\EntregaBeneficiosServicios;
use App\Services\RevocacionBeneficiosServicios;
use Illuminate\Console\Command;
class DeliverServiceBenefits extends Command
{
    protected $signature = 'comunidad:entregar-beneficios';
    protected $description = 'Entrega becas E5 de lockers e impresiones y procesa sus revocaciones pendientes.';
    public function handle(EntregaBeneficiosServicios $delivery, RevocacionBeneficiosServicios $revocations): int
    {
        $count = 0;
        foreach (AsignacionBeneficio::whereIn('estado', ['pendiente_integracion', 'revocacion_pendiente'])
            ->where('contrato.equipo_destino', 5)->whereIn('contrato.tipo', ['locker', 'impresiones'])
            ->where(fn ($q) => $q->whereNull('proximo_intento_en')->orWhere('proximo_intento_en', '<=', now()))->limit(100)->get() as $a) {
            if ($a->estado === 'revocacion_pendiente') { $revocations->procesar($a); }
            else { $delivery->entregar($a); }
            $count++;
        }
        $this->info('Beneficios revisados: '.$count.'.');
        return self::SUCCESS;
    }
}
