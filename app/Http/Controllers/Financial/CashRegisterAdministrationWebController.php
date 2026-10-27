<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Financial\Concerns\FinancialWebActor;
use Inertia\Inertia;
use Inertia\Response;

class CashRegisterAdministrationWebController extends CashRegisterAdministrationController
{
    use FinancialWebActor;

    public function index(): Response
    {
        return Inertia::render('Financial/CashRegisters');
    }
}
