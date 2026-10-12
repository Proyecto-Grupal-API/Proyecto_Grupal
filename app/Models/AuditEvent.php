<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class AuditEvent extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'commercial_audit_events';
    protected $fillable = ['actor_id','actor_name','business_id','entity_type','entity_id','action','from_status','to_status','details','occurred_at'];
    protected $casts = ['details' => 'array', 'occurred_at' => 'datetime'];
}
