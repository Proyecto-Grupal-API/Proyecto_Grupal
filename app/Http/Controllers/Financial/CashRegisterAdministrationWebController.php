<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Financial\Concerns\FinancialWebActor;

class CashRegisterAdministrationWebController extends CashRegisterAdministrationController
{
    use FinancialWebActor;

    public function index(): \Inertia\Response
    {
        return \Inertia\Inertia::render('Financial/CashRegisters');
    }
}
