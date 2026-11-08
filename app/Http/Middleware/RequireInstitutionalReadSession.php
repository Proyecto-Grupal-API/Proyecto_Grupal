<?php
namespace App\Http\Middleware;
use App\Domains\Financial\Services\InstitutionalBudgetReadAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;
final class RequireInstitutionalReadSession
{
    public function __construct(private readonly InstitutionalBudgetReadAccess $access) {}
    public function handle(Request $request, Closure $next): Response
    {
        try { $this->access->actor($request); }
        catch (AuthorizationException $e) { return response('Se requiere una cuenta habilitada, segundo factor confirmado y sesion vigente.',403)->header('Cache-Control','no-store, private'); }
        catch (Throwable $e) { return response('No se pudo verificar la sesion.',503)->header('Cache-Control','no-store, private'); }
        return $next($request);
    }
}
