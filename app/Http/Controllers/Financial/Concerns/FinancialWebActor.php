<?php

namespace App\Http\Controllers\Financial\Concerns;

use Illuminate\Http\Request;

trait FinancialWebActor
{
    protected function authenticatedActor(Request $request): string
    {
        abort_unless($request->user(), 401);
        return 'user:' . $request->user()->getKey();
    }
}
