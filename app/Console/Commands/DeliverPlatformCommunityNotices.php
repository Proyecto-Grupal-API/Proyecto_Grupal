<?php

namespace App\Console\Commands;

use App\Services\PlatformModule\CommunityNotices;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class DeliverPlatformCommunityNotices extends Command
{
    protected $signature = 'plataforma:entregar-avisos-comunidad {--limit=30 : Máximo de entregas, entre 1 y 100}';
    protected $description = 'Encola hechos durables de recompensas E7 y entrega avisos idempotentes a E6.';

    public function handle(CommunityNotices $notices): int
    {
        $raw = $this->option('limit');
        $limit = is_string($raw) && preg_match('/\A[1-9][0-9]*\z/', $raw)
            ? filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]) : false;
        if ($limit === false) {
            $this->error('El límite debe ser un entero entre 1 y 100.');

            return self::INVALID;
        }
        if (config('platform.notices.enabled', false) !== true) {
            $this->line('{"status":"DISABLED"}');

            return self::SUCCESS;
        }
        try {
            $scan = $notices->scan($limit);
            $delivery = $notices->deliver($limit);
            $this->line(json_encode(['scan' => $scan, 'delivery' => $delivery], JSON_THROW_ON_ERROR));

            return $scan['invalid'] > 0 || $delivery['pending'] > 0 ? self::FAILURE : self::SUCCESS;
        } catch (InvalidArgumentException $error) {
            $this->error($error->getMessage());

            return self::INVALID;
        } catch (Throwable) {
            $this->error('No se completaron los avisos de recompensas. Revisa la infraestructura y reintenta.');

            return self::FAILURE;
        }
    }
}
