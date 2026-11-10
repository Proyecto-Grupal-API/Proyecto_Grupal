<?php
namespace App\Console\Commands;

use App\Domains\Financial\Services\InstitutionalBecaExpirationService;
use Illuminate\Console\Command;
use Throwable;

final class ExpireInstitutionalBecas extends Command
{
    protected $signature = 'financial:expire-institutional-becas
        {--actor= : Identidad auditada con permiso budget.expire; por defecto usa configuracion}
        {--limit=100 : Tamano del lote, entre 1 y 500}
        {--after=0 : Cursor numerico exclusivo}
        {--all : Recorrer todos los lotes hasta el limite superior inicial}
        {--confirm : Ejecutar liberaciones; sin esta opcion solo consulta}';
    protected $description = 'Revisa o vence BECA institucionales sin crear efectivo ni alterar obligaciones.';

    public function handle(InstitutionalBecaExpirationService $service): int
    {
        $actor = $this->option('actor') ?: config('financial_institutional_expiration.actor_id');
        $limit = $this->number($this->option('limit'), 1, 500);
        $cursor = $this->number($this->option('after'), 0, PHP_INT_MAX);
        if (!is_string($actor) || !preg_match('/\A[0-9a-f]{24}\z/i', $actor) || $limit === null || $cursor === null) {
            $this->line('{"status":"INVALID_INPUT","message":"Configura un actor de 24 caracteres hexadecimales y limites enteros validos."}');
            return self::INVALID;
        }
        $through = null; $cutoff = null; $failed = false;
        try {
            do {
                $r = $service->batch($actor, (bool) $this->option('confirm'), $limit, $cursor, $through, $cutoff);
                $this->line(json_encode($r, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                foreach (['DENIED','REVIEW_REQUIRED','UNAVAILABLE'] as $code) $failed = $failed || ($r['counts'][$code] ?? 0) > 0;
                $cursor = $r['next_cursor']; $through = $r['through']; $cutoff = $r['cutoff'];
            } while ($this->option('all') && $r['has_more']);
        } catch (Throwable) {
            $this->line('{"status":"UNAVAILABLE","message":"No se completo el barrido. Revisa la infraestructura y reintenta."}');
            return self::FAILURE;
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function number(mixed $raw, int $min, int $max): ?int
    {
        if (!is_string($raw) || !preg_match('/\A(?:0|[1-9][0-9]*)\z/', $raw)) return null;
        $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        return $value === false ? null : $value;
    }
}
