<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use CampusDigital\Authorization\Kernel;
use App\Models\User;
class CreateCashDevelopmentContext extends Command {
    protected $signature='financial:cash-dev-context {--identity=} {--reason=} {--confirm}';
    protected $description='Registra únicamente dev-financial-cash como contexto sintético local, sin asignar roles ni operar dinero';
    public function handle(Kernel $kernel):int {
        if(!app()->environment('local','testing')){$this->error('Disponible solo en local/testing.');return self::FAILURE;}
        $identity=strtolower((string)$this->option('identity'));$reason=trim((string)$this->option('reason'));
        if(!$this->option('confirm')||!preg_match('/^[a-f0-9]{24}$/',$identity)||$reason===''||mb_strlen($reason)>1000){$this->error('Indica identity, reason y confirm.');return self::FAILURE;}
        try{
            $principal=$kernel->principalFor($identity);$user=User::find($identity);
            if(!$principal||!$kernel->check($principal,'campus.roles.manage')->allowed()||!$user?->two_factor_secret||!$user?->two_factor_confirmed_at){$this->error('Se requiere plaza global activa y 2FA confirmado.');return self::FAILURE;}
            DB::connection('mongodb')->transaction(function()use($kernel,$principal,$identity,$reason){
                if(!$kernel->check($principal,'campus.roles.manage')->allowed())throw new \DomainException('Plaza revocada.');
                $table=DB::connection('mongodb')->table('campus_authorization_contexts');
                $existing=$table->where('type','association')->where('reference','dev-financial-cash')->first();
                if($existing){$a=(array)$existing;if(($a['source']??null)!=='development_fixture'||!($a['active']??false))throw new \DomainException('Contexto existente incompatible.');return;}
                DB::connection('mongodb')->table('campus_authorization_contexts')->insert([
                    'type'=>'association','reference'=>'dev-financial-cash','label'=>'Asociación sintética de pruebas de Caja',
                    'source'=>'development_fixture','active'=>true,'registration_id'=>(string)Str::uuid(),
                    'registered_by'=>$identity,'reason'=>$reason,'registered_at'=>now()->toISOString(),
                ]);
            });
            $this->info('Contexto disponible: dev-financial-cash. No se asignaron roles ni se registró efectivo.');return self::SUCCESS;
        }catch(\Throwable $e){report($e);$this->error('No se pudo registrar el contexto. No se otorgaron permisos.');return self::FAILURE;}
    }
}
