<?php
namespace App\Domains\Financial\Services;
use App\Domains\Financial\Contracts\TransferSessionContextProvider;
use App\Domains\Financial\Data\TransferAuditContext;
use App\Domains\Financial\Support\FinancialCorrelation;
use Illuminate\Http\Request;
class TransferAuditContextResolver
{
    public function __construct(private readonly TransferSessionContextProvider $sessions) {}
    public function resolve(Request $request, string $actor): TransferAuditContext
    {
        $client = $request->attributes->get('oauth_client_id');
        if (is_string($client) && $client !== '') {
            // The current delegation contract supplies identity only; never invent a device from request headers.
            return new TransferAuditContext('API', $client, $request->ip(), app(FinancialCorrelation::class)->id());
        }
        $id = $request->hasSession() ? $request->session()->get('cd_session_id') : null;
        $context = $this->sessions->resolve($actor, is_string($id) ? $id : null);
        return new TransferAuditContext('WEB', null, $request->ip(), app(FinancialCorrelation::class)->id(),
            $context['session_id'] ?? null, $context['device_id'] ?? null, $context['status'] ?? 'NOT_PROVIDED');
    }
}
