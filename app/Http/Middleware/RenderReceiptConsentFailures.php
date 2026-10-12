<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
class RenderReceiptConsentFailures {
    public function handle(Request $request, Closure $next) {
        try { return $next($request); }
        catch (ValidationException $e) { throw $e; }
        catch (\Throwable $e) {
            if ($e instanceof HttpExceptionInterface) throw $e;
            logger()->error('Receipt consent dependency failed.', ['exception_type'=>$e::class]);
            return response()->json(['message'=>'La autorización de comprobantes no está disponible.'],503)
                ->header('Cache-Control','private, no-store');
        }
    }
}
