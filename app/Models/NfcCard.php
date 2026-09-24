<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class NfcCard extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'nfc_cards';

    protected $fillable = [
        'user_id',
        'uid',
        'registered_by',
        'status',
        'registered_at',
        'blocked_at',
        'replaced_at',
        'replacement_of_card_id',
        'replaced_by_card_id',
    ];

    protected $casts = [
        'registered_at' => 'datetime',
        'blocked_at' => 'datetime',
        'replaced_at' => 'datetime',
    ];

    /**
     * Usuario propietario de la tarjeta.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Usuario que registró la tarjeta.
     */
    public function registeredBy()
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    /**
     * Historial de eventos de la tarjeta.
     */
    public function credentialEvents()
    {
        return $this->hasMany(CredentialEvent::class, 'nfc_card_id');
    }

    public function replacementOf()
    {
        return $this->belongsTo(self::class, 'replacement_of_card_id');
    }

    public function replacedBy()
    {
        return $this->belongsTo(self::class, 'replaced_by_card_id');
    }
}
