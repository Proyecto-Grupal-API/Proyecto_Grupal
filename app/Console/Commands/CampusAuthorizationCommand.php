<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use CampusDigital\Authorization\Kernel;
final class CampusAuthorizationCommand extends Command {
    protected $signature='campus:authorization {operation : register|bootstrap|seat|grant|revoke|retire|check|catalog|snapshot} {--identity=} {--actor=} {--principal=} {--module=} {--capabilities=} {--capability=} {--scope-type=} {--scope-id=} {--all-contexts} {--expires=} {--grant=} {--reason=} {--confirm}';
    protected $description='Administración independiente y explícita de autorización del campus';
    public function handle(Kernel $kernel): int {
        if (!app()->environment('local','testing')) { $this->error('Esta herramienta inicial solo está habilitada en desarrollo; producción requiere un transporte administrativo autenticado.'); return self::FAILURE; }
        try {
            $op=$this->argument('operation');
            $scope=$this->option('scope-type')!==null || $this->option('scope-id')!==null ? ['type'=>$this->option('scope-type'),'id'=>$this->option('scope-id')] : null;
            if (!in_array($op,['check','catalog','snapshot'],true) && !$this->option('confirm')) throw new \DomainException('Indica --confirm para modificar.');
            $actor=(string)$this->option('actor'); $principal=(string)$this->option('principal'); $reason=(string)$this->option('reason');
            $result=match($op) {
                'register'=>$kernel->register((string)$this->option('identity')),
                'bootstrap'=>$kernel->seat(null,(int)$this->option('module'),$principal,$reason,true),
                'seat'=>$kernel->seat($actor,(int)$this->option('module'),$principal,$reason),
                'grant'=>$kernel->grant($actor,$principal,(int)$this->option('module'),explode(',',(string)$this->option('capabilities')),$scope,(bool)$this->option('all-contexts'),$this->option('expires')!==null ? $this->expiry((string)$this->option('expires')) : null,$reason),
                'revoke'=>$kernel->revoke($actor,(string)$this->option('grant'),$reason),
                'retire'=>$kernel->retire($actor,$principal,$reason),
                'check'=>$kernel->check($principal,(string)$this->option('capability'),$scope),
                'catalog'=>config('campus_authorization.catalog'),
                'snapshot'=>$kernel->snapshot(),
                default=>throw new \InvalidArgumentException('Operación desconocida.'),
            };
            $this->line(json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)); return self::SUCCESS;
        } catch (\DomainException|\InvalidArgumentException|\LogicException $e) { $this->error($e->getMessage()); return self::FAILURE; }
        catch (\Throwable $e) { report($e); $this->error('Dependencia de autorización no disponible; operación rechazada.'); return self::FAILURE; }
    }
    private function expiry(string $value): int { $t=strtotime($value); if ($t===false) throw new \InvalidArgumentException('Fecha inválida.'); return $t; }
}
