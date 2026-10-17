<?php
namespace App\Observers;
use App\Services\AuditService;
use Illuminate\Support\Facades\Auth;

class MongoAuditObserver {
    public function created($model) { $this->log('created', $model); }
    public function updated($model) { $this->log('updated', $model, $model->getOriginal()); }
    public function deleted($model) { $this->log('deleted', $model); }
    private function log(string $action, $model, array $before = []) {
        try {
            app(AuditService::class)->log(
                action: class_basename($model) . ".{$action}",
                actorId: (string) (Auth::id() ?? 'system'),
                payload: ['id' => (string) $model->getKey(), 'before' => $before, 'after' => $model->toArray()]
            );
        } catch (\Throwable $e) { \Log::error('Audit failed', ['error' => $e->getMessage()]); }
    }
}