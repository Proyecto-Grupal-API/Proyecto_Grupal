<?php
namespace App\Http\Controllers\Financial;
use App\Http\Controllers\Financial\Concerns\FinancialWebActor;
use Inertia\Inertia;
use Inertia\Response;
class CashApprovalWebController extends CashApprovalController {
    use FinancialWebActor;
    public function index(): Response { return Inertia::render('Financial/CashApprovals'); }
}
