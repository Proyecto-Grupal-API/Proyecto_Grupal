<?php
namespace App\Domains\Financial\Services;

use App\Domains\Financial\Enums\{BonusStatus, BonusType};
use App\Domains\Financial\Models\{Bonus, InstitutionalBonusBudget};
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/** Bounded keyset scan. Each expiration owns its existing independent release transaction. */
final class InstitutionalBecaExpirationService
{
    public const REASON = 'Vencimiento automatico de BECA institucional; liberar solo importe no consumido.';

    public function __construct(private readonly InstitutionalBecaReleaseService $release) {}

    public function batch(string $actor, bool $execute = false, int $limit = 100, int $after = 0,
        ?int $through = null, ?string $cutoff = null): array
    {
        if (!preg_match('/\A[0-9a-f]{24}\z/i', $actor) || $limit < 1 || $limit > 500 || $after < 0
            || ($through !== null && $through < 0)) throw new InvalidArgumentException('Actor o limites no validos.');
        // The cutoff/high-water mark stay fixed while the CLI traverses successive batches.
        $instant = $cutoff === null ? now() : \Illuminate\Support\Carbon::parse($cutoff);
        $through ??= (int) Bonus::max('id');
        $rows = $this->due($instant)->where('bonuses.id', '>', $after)->where('bonuses.id', '<=', $through)
            ->orderBy('bonuses.id')->limit($limit + 1)->get(['bonuses.id','bonuses.public_id','bonuses.institutional_budget_id']);
        $more = $rows->count() > $limit;
        $items = []; $cursor = $after;
        foreach ($rows->take($limit) as $candidate) {
            $cursor = (int) $candidate->id;
            $id = strtolower((string) $candidate->public_id);
            $result = ['bonus_id' => $id];
            if (!$execute) { $items[] = [...$result, 'status' => 'PREVIEW']; continue; }
            try {
                $budgetId = $candidate->getRawOriginal('institutional_budget_id');
                if (!is_string($budgetId) || !Str::isUuid($budgetId) || !Str::isUuid($id)) {
                    throw new ConflictHttpException('Vinculo no valido.');
                }
                $budget = InstitutionalBonusBudget::where('public_id', $budgetId)->first();
                if (!$budget) throw new ConflictHttpException('Presupuesto ausente.');
                // Optimistic version plus the domain's budget->bonus locks reject races safely.
                $r = $this->release->expire(strtolower($actor), strtolower($budgetId), $id, $budget->version,
                    self::REASON, 'institutional-beca-expire:'.$id);
                $result += ['status' => $r['replayed'] ? 'REPLAYED' : 'EXPIRED',
                    'operation_id' => strtolower($r['operation']->public_id),
                    'released_amount_cents' => (string) $r['operation']->after_data['released_amount_cents']];
            } catch (AuthorizationException) {
                $result['status'] = 'DENIED';
            } catch (ConflictHttpException) {
                $result['status'] = 'REVIEW_REQUIRED';
            } catch (Throwable) {
                // Never echo exception messages, SQL, connection details or credentials.
                $result['status'] = 'UNAVAILABLE';
            }
            $items[] = $result;
        }
        $counts = array_count_values(array_column($items, 'status'));
        return ['mode' => $execute ? 'EXECUTE' : 'PREVIEW', 'cutoff' => $instant->toISOString(),
            'through' => $through, 'next_cursor' => $cursor, 'has_more' => $more,
            'counts' => $counts, 'items' => $items];
    }

    private function due(\DateTimeInterface $instant): Builder
    {
        return Bonus::query()->where('bonuses.type', BonusType::BECA->value)
            ->whereIn('bonuses.status', [BonusStatus::ACTIVO->value, BonusStatus::PENDIENTE->value])
            ->where('bonuses.expires_at', '<=', $instant)
            ->where(function (Builder $q) {
                $q->whereNotNull('bonuses.institutional_budget_id')
                    ->orWhereExists(function ($e) { $e->selectRaw('1')->from('bonus_budget_commitments as c')
                        ->whereColumn('c.bonus_id', 'bonuses.public_id'); })
                    ->orWhereExists(function ($e) { $e->selectRaw('1')->from('institutional_bonus_obligations as o')
                        ->whereColumn('o.bonus_id', 'bonuses.public_id'); });
            });
    }
}
