<?php

namespace App\Console\Commands;

use App\Domains\Financial\Notifications\FinancialCommunityNotices;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class DeliverFinancialCommunityNotices extends Command
{
    protected $signature = 'financial:community-notices {--limit=30 : Candidatos por tipo y máximo de entregas, entre 1 y 500}';
    protected $description = 'Encola hechos financieros confirmados y entrega avisos idempotentes a Comunidad.';

    public function handle(FinancialCommunityNotices $notices): int
    {
        if (config('financial_community_notices.enabled') !== true) {
            $this->line('{"status":"DISABLED"}');

            return self::SUCCESS;
        }
        $raw = $this->option('limit');
        $limit = is_string($raw) && preg_match('/\A[1-9][0-9]*\z/', $raw)
            ? filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]) : false;
        if ($limit === false) {
            $this->error('El límite debe ser un entero entre 1 y 500.');

            return self::INVALID;
        }
        try {
            $scan = $notices->scan($limit);
            $delivery = $notices->deliver($limit);
            $this->line(json_encode(['scan' => $scan, 'delivery' => $delivery], JSON_THROW_ON_ERROR));

            return $delivery['pending'] > 0 || $scan['invalid'] > 0 ? self::FAILURE : self::SUCCESS;
        } catch (InvalidArgumentException $error) {
            $this->error($error->getMessage());

            return self::INVALID;
        } catch (Throwable) {
            $this->error('No se completaron los avisos. Revisa la infraestructura y reintenta.');

            return self::FAILURE;
        }
    }
}
