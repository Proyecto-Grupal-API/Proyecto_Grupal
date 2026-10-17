<?php
namespace App\Models\InventoryModule;

use MongoDB\Laravel\Eloquent\Model;

abstract class Team4Document extends Model
{
    protected static function booted(): void
    {
        static::observe(\App\Observers\InventoryAuditObserver::class);
    }

    protected $connection = 'inventory';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $guarded = ['_id'];

    protected $casts = [
        'active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
