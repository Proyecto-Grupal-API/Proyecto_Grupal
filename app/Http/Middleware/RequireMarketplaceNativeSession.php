<?php
namespace App\Http\Middleware;
use App\Domains\Financial\Services\MarketplaceCheckoutService;
use Closure;
use Illuminate\Http\Request;
final class RequireMarketplaceNativeSession
{
    public function handle(Request $r,Closure $next){
        $s=app(MarketplaceCheckoutService::class);
        if($s->enabled())$s->session($r); // Before device.track can create a missing registry entry.
        return $next($r);
    }
}
