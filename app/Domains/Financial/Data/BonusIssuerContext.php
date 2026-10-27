<?php

namespace App\Domains\Financial\Data;

final readonly class BonusIssuerContext
{
    public function __construct(public ?string $scopeType = null, public ?string $scopeId = null)
    {
        if (($scopeType === null) !== ($scopeId === null) || ($scopeType !== null && (! in_array($scopeType, ['business', 'association', 'service', 'council'], true)
            || $scopeId === '' || strlen($scopeId) > 100 || preg_match('/[\x00-\x20\x7f]/', $scopeId)))) {
            throw new \InvalidArgumentException('El contexto de emisión es inválido.');
        }
    }

    public function toArray(): array
    {
        return ['issuer_scope_type' => $this->scopeType, 'issuer_scope_id' => $this->scopeId];
    }
}
