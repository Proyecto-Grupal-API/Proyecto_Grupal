<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
use CampusDigital\Authorization\{Kernel,Store,Directory};
final class CampusAuthorizationServiceProvider extends ServiceProvider {
    public function boot(): void {
        \Inertia\Inertia::share('campusAuthorization',function() {
            if (!config('campus_authorization.enabled') || !auth()->check()) return ['canManage'=>false];
            try { $k=$this->app->make(\CampusDigital\Authorization\Kernel::class); $id=$k->principalFor((string)auth()->id()); return ['canManage'=>$id && $k->check($id,'campus.roles.manage')->allowed()]; }
            catch (\Throwable $e) { report($e); return ['canManage'=>false]; }
        });
        $this->loadRoutesFrom(base_path('routes/campus_authorization.php'));
        $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)->renderable(function(\Throwable $e, $r) {
            if (!$r->is('administracion/autorizaciones*')) return null;
            if ($e instanceof \DomainException || $e instanceof \InvalidArgumentException || $e instanceof \App\Domains\CampusAuthorization\AuthorityConflict) return response()->json(['message'=>'El cambio no se pudo aplicar. Actualiza los datos y revisa plazas, permisos y vigencia.','code'=>'AUTHORITY_CONFLICT'],409);
            if ((in_array((int)$e->getCode(),[11000,112,251],true) || ($e->getPrevious() && in_array((int)$e->getPrevious()->getCode(),[11000,112,251],true)))) return response()->json(['message'=>'Otro cambio está en curso. Actualiza el historial antes de reintentar.','code'=>'AUTHORITY_CONFLICT'],409);
            if ($e instanceof \MongoDB\Driver\Exception\Exception || $e instanceof \Illuminate\Database\QueryException) {
                report($e); return response()->json(['message'=>'No se pudo consultar la autorización. No se otorgaron permisos.','code'=>'AUTHORITY_UNAVAILABLE'],503);
            }
            return null;
        });
    }
    public function register(): void {
        require_once base_path('packages/campus-authorization/Kernel.php');
        $this->app->bind(Store::class,\App\Domains\CampusAuthorization\MongoStore::class);
        $this->app->bind(Directory::class,\App\Domains\CampusAuthorization\Module1Directory::class);
        $this->app->bind(Kernel::class,fn($app)=>new Kernel($app->make(Store::class),$app->make(Directory::class),config('campus_authorization.catalog'),config('campus_authorization.enabled')===true));
        $this->commands([\App\Console\Commands\CampusAuthorizationCommand::class]);
    }
}
