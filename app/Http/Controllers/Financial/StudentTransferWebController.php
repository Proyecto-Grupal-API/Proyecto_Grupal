<?php
namespace App\Http\Controllers\Financial;
use App\Domains\Financial\Contracts\TransferAuthorizationProvider;
use App\Domains\Financial\Enums\StudentTransferKind;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;
use App\Domains\Financial\Exceptions\FinancialLimitExceededException;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\StudentTransfer;
use App\Domains\Financial\Models\StudentTransferConfirmation;
use App\Domains\Financial\Models\StudentTransferPolicyChange;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\StudentTransferConfirmationService;
use App\Domains\Financial\Services\StudentTransferPolicyService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use InvalidArgumentException;
class StudentTransferWebController extends StudentTransferJsonController
{
    protected function actor(Request $r): string { return strtolower((string) $r->user()->getKey()); }
    public function index(Request $r)
    {
        $actor = $this->actor($r);
        $wallets = Wallet::where('owner_type', 'USER')->where('owner_id', $actor)->where('type', WalletType::USUARIO->value)->where('currency', 'MXN')->limit(2)->get();
        $w = $wallets->count() === 1 ? $wallets->first() : null;
        return Inertia::render('Financial/Transfers', ['wallet' => $w ? ['id' => strtolower($w->public_id), 'available_balance_cents' => $w->available_balance_cents, 'held_balance_cents' => $w->held_balance_cents] : null,
            'policy' => $this->policies->snapshot($this->policies->current()),
            'permissions' => ['send' => $w !== null && $this->authorization->canSend($actor, $w), 'manage' => $this->authorization->canManagePolicy($actor)]]);
    }
}
