<?php
namespace App\Observers;

use App\Services\AuditService;
use App\Services\IdentityService;

class MongoAuditObserver
{
    public function created($model): void
    {
        $this->log('created', $model);
    }

    public function updated($model): void
    {
        $this->log('updated', $model, $model->getOriginal());
    }

    public function deleted($model): void
    {
        $this->log('deleted', $model);
    }

    private function log(string $action, $model, array $before = []): void
    {
        $actorId = app(IdentityService::class)->resolveCurrentUserId();
        app(AuditService::class)->log(class_basename($model) . ".{$action}", [
            'actor_id' => $actorId,
            'business_id' => $model->business_id ?? config('team4.business_id'),
            'entity_type' => class_basename($model),
            'entity_id' => (string) $model->getKey(),
            'action' => $action,
            'before' => $before ?: null,
            'after' => $model->toArray(),
        ]);
    }
}