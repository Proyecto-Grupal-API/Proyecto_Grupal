<?php

namespace App\Services\PlatformModule;

use Closure;
use Illuminate\Support\Facades\DB;

/** Join an existing Mongo transaction: the driver cannot start a nested one. */
final class PlatformTransaction
{
    public static function run(Closure $operation): mixed
    {
        $db = DB::connection('platform');

        return $db->getSession()?->isInTransaction() ? $operation() : $db->transaction($operation);
    }
}
