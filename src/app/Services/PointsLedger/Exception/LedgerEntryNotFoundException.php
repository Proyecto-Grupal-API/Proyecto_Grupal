<?php

namespace App\Services\PointsLedger\Exception;

use RuntimeException;

class LedgerEntryNotFoundException extends RuntimeException
{
    public static function forId(string $ledgerId): self
    {
        return new self("No se encontró el PointsLedger original con id [{$ledgerId}] para revertir.");
    }
}
