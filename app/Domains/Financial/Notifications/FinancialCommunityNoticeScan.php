<?php

namespace App\Domains\Financial\Notifications;

use Illuminate\Database\Eloquent\Model;

final class FinancialCommunityNoticeScan extends Model
{
    protected $connection = 'sqlsrv';
    protected $primaryKey = 'tipo';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['after_id' => 'integer', 'through_id' => 'integer'];
}
