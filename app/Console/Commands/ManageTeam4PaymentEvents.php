<?php

namespace App\Console\Commands;

use App\Domains\Financial\Integrations\Team4\Team4OutboxAdministration;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ManageTeam4PaymentEvents extends Command
{
    protected $signature = 'financial:team4-events
        {action=list : list, show o quarantine}
        {event? : UUID del evento, no del pago}
        {--state= : Filtro de estado de envio}
        {--payment= : Identificador financiero del pago}
        {--limit=20 : Tamano de pagina, 1..100}
        {--before-id= : Cursor de pagina anterior}
        {--body-hash= : SHA256 del contenido revisado}
        {--action-id= : UUID estable de la accion operativa}
        {--operator= : Etiqueta declarada del operador local}
        {--reason= : Motivo de 10..1000 caracteres, sin secretos}
        {--confirm : Aplicar la cuarentena; sin esto solo muestra una vista previa}';

    protected $description = 'Consultar eventos Team4 o apartar en local eventos nunca intentados; no envia HTTP ni mueve dinero.';

    public function handle(Team4OutboxAdministration $administration): int
    {
        try {
            $action = $this->argument('action');
            $event = $this->argument('event');
            if ($action !== 'quarantine' && $this->option('confirm')) {
                throw new InvalidArgumentException('--confirm solo se admite con quarantine.');
            }
            if ($action === 'list') {
                if ($event !== null) throw new InvalidArgumentException('list no recibe un evento. Usa --payment o --state.');
                $result = $administration->listing($this->option('state'), $this->option('payment'),
                    $this->positiveInteger($this->option('limit'), 100),
                    $this->option('before-id') !== null ? $this->positiveInteger($this->option('before-id'), PHP_INT_MAX) : null);
            } elseif ($action === 'show') {
                if (!is_string($event)) throw new InvalidArgumentException('show requiere el UUID del evento.');
                $result = $administration->show($event);
            } elseif ($action === 'quarantine') {
                if (!is_string($event)) throw new InvalidArgumentException('quarantine requiere el UUID del evento.');
                if (!$this->option('confirm')) {
                    $result = ['preview_only' => true, 'event' => $administration->show($event),
                        'effect' => 'Solo PENDING sin intentos puede pasar a REVIEW. Bloquea posteriores del mismo pago.',
                        'required_to_apply' => '--body-hash, --action-id, --operator, --reason y --confirm; APP_ENV local/testing'];
                } else {
                    $result = $administration->quarantine($event, (string) $this->option('body-hash'),
                        (string) $this->option('action-id'), (string) $this->option('operator'), (string) $this->option('reason'));
                }
            } else {
                throw new InvalidArgumentException('Accion no valida: usa list, show o quarantine.');
            }
            $this->line(json_encode(['emitter_enabled' => (bool) config('financial_team4.enabled'), 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (InvalidArgumentException | HttpExceptionInterface $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('No se pudo consultar o guardar la accion. Revisa migraciones y conexion; conserva el action-id para reintentar.');
            logger()->error('Team4 operational command failed.', ['exception_type' => $e::class]);
            return self::FAILURE;
        }
    }

    private function positiveInteger(mixed $value, int $max): int
    {
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value) || strlen($value) > strlen((string) $max)
            || (strlen($value) === strlen((string) $max) && strcmp($value, (string) $max) > 0)) {
            throw new InvalidArgumentException('Limite/cursor debe ser un entero positivo dentro del rango admitido.');
        }
        return (int) $value;
    }
}
