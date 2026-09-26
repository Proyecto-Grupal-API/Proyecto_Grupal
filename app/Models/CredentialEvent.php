<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class CredentialEvent extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'credential_events';

    protected $fillable = [
        'nfc_card_id',
        'performed_by',
        'event_type',
        'reason',
        'previous_status',
        'new_status',
    ];

    /**
     * Tarjeta NFC relacionada con el evento.
     */
    public function nfcCard()
    {
        return $this->belongsTo(NfcCard::class, 'nfc_card_id');
    }

    /**
     * Usuario que realizó la acción.
     */
    public function performedBy()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}