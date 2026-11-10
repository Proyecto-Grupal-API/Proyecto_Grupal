<?php
namespace App\Domains\Financial\Services;

use App\Domains\Financial\Contracts\InstitutionalBudgetAuthorization;
use App\Models\{User,UserSession};
use Illuminate\Http\Request;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** Native human session and explicit read authority; never grants rights or repairs sessions. */
final class InstitutionalBudgetReadAccess
{
    public function __construct(private readonly InstitutionalBudgetAuthorization $authority) {}
    public function actor(Request $request): string
    {
        $id=(string)$request->user()?->getKey();
        if (!preg_match('/\A[0-9a-f]{24}\z/i',$id)) throw new AuthorizationException();
        $user=User::find($id); $sessionId=$request->session()->get('cd_session_id');
        if (!$user || $user->deleted_at || $user->account_activation_pending || $user->must_change_password
            || !$user->hasVerifiedEmail() || !$user->two_factor_secret || !$user->two_factor_confirmed_at
            || !is_string($sessionId) || !preg_match('/\A[0-9a-f]{24}\z/i',$sessionId)) throw new AuthorizationException();
        $session=UserSession::find($sessionId);
        if (!$session || $session->isRevoked() || strtolower((string)$session->user_id)!==strtolower($id)) throw new AuthorizationException();
        return strtolower($id);
    }
    public function authorize(Request $request, ?string $budgetId): void
    {
        $actor=$this->actor($request);
        try {
            $allowed=$this->authority->allows($actor,'budget.read.all')
                || ($budgetId !== null && $this->authority->allows($actor,'budget.read',$budgetId));
        } catch (Throwable $e) { throw new HttpException(503,'La autorizacion de lectura no esta disponible.'); }
        if (!$allowed) throw new AuthorizationException();
    }
    public function canList(Request $request): bool
    {
        try { return $this->authority->allows($this->actor($request),'budget.read.all'); }
        catch (Throwable $e) { return false; }
    }
}
