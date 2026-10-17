<?php

namespace App\Observers;

use App\Models\InventoryModule\Team4Document;
use App\Services\InventoryModule\ActorResolver;
use App\Services\InventoryModule\AuditService;
use MongoDB\BSON\ObjectId;

class InventoryAuditObserver
{
    public function creating(Team4Document $model): void
    {
        // With non-incrementing models Mongo generates an ID that Eloquent does not retain.
        if (! $model->getKey()) {
            $model->setAttribute('_id', new ObjectId);
        }
    }

    public function created(Team4Document $model): void
    {
        $this->record('created', $model);
    }

    public function updated(Team4Document $model): void
    {
        $this->record('updated', $model, $model->getRawOriginal());
    }

    public function deleted(Team4Document $model): void
    {
        $this->record('deleted', $model, $model->getAttributes());
    }

    private function record(string $action, Team4Document $model, array $before = []): void
    {
        app(AuditService::class)->log(class_basename($model).'.'.$action, [
            'actor_id' => ActorResolver::resolve(),
            'business_id' => $model->business_id,
            'entity_type' => $model->getTable(),
            'entity_id' => (string) $model->getKey(),
            'action' => $action,
            'before' => $before,
            'after' => $action === 'deleted' ? null : $model->getAttributes(),
        ]);
    }
}
