<?php

namespace App\Domains\Financial\Models;

use Illuminate\Database\Eloquent\Model;

class ReceiptUserDelegation extends Model
{
    protected $connection = 'sqlsrv';

    protected $table = 'receipt_user_delegations';

    protected $guarded = ['id'];

    protected $hidden = ['proof_hash', 'native_session_id', 'identity_session_id', 'request_digest'];

    protected $casts = ['native_session_id' => 'encrypted', 'request_query' => 'array', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
}
