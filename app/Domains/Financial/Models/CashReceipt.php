<?php

namespace App\Domains\Financial\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CashReceipt extends Model
{
    protected $connection = 'sqlsrv';
    protected $table = 'cash_receipts';
    protected $fillable = ['public_id', 'cash_movement_id', 'folio', 'snapshot', 'issued_at'];
    protected $casts = ['cash_movement_id' => 'integer', 'snapshot' => 'array', 'issued_at' => 'datetime'];

    // Every persisted row is an issued historical document. Guard the persistence
    // methods rather than events: saveQuietly/withoutEvents must not bypass this.
    // Query-builder writes and direct SQL remain outside this model-level guard.
    public function save(array $options = [])
    {
        $this->assertNotIssued();

        return parent::save($options);
    }

    public function delete()
    {
        $this->assertNotIssued();

        return parent::delete();
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        $this->assertNotIssued();

        return parent::incrementOrDecrement($column, $amount, $extra, $method);
    }

    private function assertNotIssued(): void
    {
        if ($this->exists) {
            throw new LogicException('Los comprobantes de caja emitidos son inmutables.');
        }
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(CashMovement::class, 'cash_movement_id');
    }
}
