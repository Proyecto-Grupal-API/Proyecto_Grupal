<?php

namespace App\Domains\Financial\Exceptions;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class IdentityFinancialAuthorizationUnavailable extends FinancialDependencyUnavailableException
{
    public function __construct(public readonly string $reasonCode, public readonly ?int $upstreamStatus = null)
    {
        // No upstream body, request headers, token, secret, subject or previous HTTP exception.
        parent::__construct('No se pudo verificar la autorizacion de emision en Identidad.');
    }

    public function report(): bool
    {
        Log::warning('Identity financial authorization unavailable.', [
            'reason_code' => $this->reasonCode, 'upstream_status' => $this->upstreamStatus,
        ]);
        return true;
    }

    public function render(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $message = 'No se pudo verificar la autorizacion en Identidad. No se autorizo esta operacion.';
        return $request->expectsJson()
            ? response()->json(['message' => $message], 503, ['Cache-Control' => 'private, no-store'])
            : response($message, 503, ['Cache-Control' => 'private, no-store']);
    }
}
