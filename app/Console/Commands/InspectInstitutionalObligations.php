<?php
namespace App\Console\Commands;

use App\Domains\Financial\Services\InstitutionalObligationQueryService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class InspectInstitutionalObligations extends Command
{
    protected $signature = 'financial:institutional-obligations
        {--budget= : UUID del presupuesto; sin filtro lista presupuestos}
        {--business= : Negocio exacto; requiere budget}
        {--details : Listar obligaciones y compras; requiere budget}
        {--page=1 : Pagina positiva}
        {--per-page=10 : Registros por pagina, de 1 a 20}';
    protected $description = 'Consulta y concilia obligaciones BECA sin modificar saldos ni ejecutar pagos';

    public function handle(InstitutionalObligationQueryService $service): int
    {
        try {
            $page = (string)$this->option('page'); $size = (string)$this->option('per-page');
            if (!preg_match('/\A[1-9][0-9]{0,6}\z/',$page) || !preg_match('/\A[1-9][0-9]?\z/',$size)) throw new InvalidArgumentException('Paginacion no valida.');
            $result = $service->report($this->option('budget'),$this->option('business'),(int)$page,(int)$size,(bool)$this->option('details'));
            $this->line(json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            return $result['consistent'] === false ? 2 : self::SUCCESS;
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage()); return self::FAILURE;
        } catch (Throwable $e) {
            // No raw SQL, connection details or payloads in operator JSON/error output.
            $this->error('No se completo la consulta institucional. Revisa el esquema y la disponibilidad de SQL Server. No se certifico conciliacion.');
            return self::FAILURE;
        }
    }
}
