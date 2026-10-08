<?php

namespace App\Services;

use App\Models\EventOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;

class OutboxDelivery
{
    private function collection(): Collection
    {
        return DB::connection('mongodb')->getCollection((new EventOutbox)->getTable());
    }

    private function date(int $timestampSeconds): UTCDateTime
    {
        return new UTCDateTime($timestampSeconds * 1000);
    }

    private function availableFilter(): array
    {
        $now = $this->date(now()->getTimestamp());

        return [
            'published_at' => null,
            '$and' => [
                ['$or' => [
                    ['next_attempt_at' => null],
                    ['next_attempt_at' => ['$lte' => $now]],
                ]],
                ['$or' => [
                    ['claim_expires_at' => null],
                    ['claim_expires_at' => ['$lte' => $now]],
                ]],
            ],
        ];
    }

    public function candidates(int $limit): iterable
    {
        return $this->collection()->find($this->availableFilter(), [
            'sort' => ['occurred_at' => 1, '_id' => 1],
            'limit' => $limit,
        ]);
    }

    /** @return array{event: object, token: string}|null */
    public function claim(object $candidate, int $leaseSeconds): ?array
    {
        $token = (string) Str::uuid();
        $filter = $this->availableFilter();
        $filter['_id'] = $candidate->_id;

        $event = $this->collection()->findOneAndUpdate($filter, [
            '$set' => [
                'claim_token' => $token,
                'claim_expires_at' => $this->date(now()->getTimestamp() + $leaseSeconds),
            ],
        ]);

        return $event ? ['event' => $event, 'token' => $token] : null;
    }

    public function acknowledge(object $event, string $token): bool
    {
        $result = $this->collection()->updateOne(
            ['_id' => $event->_id, 'claim_token' => $token, 'published_at' => null],
            [
                '$set' => ['published_at' => $this->date(now()->getTimestamp()), 'last_error' => null],
                '$unset' => ['claim_token' => '', 'claim_expires_at' => '', 'next_attempt_at' => ''],
            ],
        );

        return $result->getModifiedCount() === 1;
    }

    public function fail(object $event, string $token, string $error, bool $retryable): bool
    {
        $attempt = ((int) ($event->attempts ?? 0)) + 1;
        $delay = $retryable ? min(60, 2 ** min($attempt, 6)) : 300;
        $result = $this->collection()->updateOne(
            ['_id' => $event->_id, 'claim_token' => $token, 'published_at' => null],
            [
                '$inc' => ['attempts' => 1],
                '$set' => [
                    'last_error' => $error,
                    'next_attempt_at' => $this->date(now()->getTimestamp() + $delay),
                ],
                '$unset' => ['claim_token' => '', 'claim_expires_at' => ''],
            ],
        );

        return $result->getModifiedCount() === 1;
    }
}
