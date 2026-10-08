<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;

trait ExecutesMongoAtomically
{
    /**
     * MongoDB does not support nested transactions on a session. When a caller
     * (including RefreshDatabase) already owns one, this operation participates
     * in it; otherwise it owns a new transaction.
     */
    protected function mongoTransaction(Closure $callback): mixed
    {
        $connection = DB::connection('mongodb');

        if ($connection->transactionLevel() > 0) {
            return $callback();
        }

        return $connection->transaction($callback);
    }
}
