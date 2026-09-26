<?php

namespace App\Services;

use App\Support\QrLookupHash;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;

/** Non-destructive, resumable preparation of legacy QR documents. */
class QrLegacySecretBackfill
{
    private Collection $tokens;

    public function __construct()
    {
        $this->tokens = DB::connection('mongodb')->getCollection('qr_tokens');
    }

    public function collection(): Collection
    {
        return $this->tokens;
    }

    /** @return array{status:string, updates:array<string,mixed>, reason:?string} */
    public function analyze(array $document): array
    {
        $type = $document['type'] ?? null;
        if (! in_array($type, ['dynamic', 'identification'], true) || ! isset($document['_id'])) {
            return $this->result('UNKNOWN/CORRUPT', reason: 'type_or_id');
        }
        if (! is_string($document['user_id'] ?? null) || $document['user_id'] === '' ||
            ! (($document['expires_at'] ?? null) instanceof UTCDateTime)) {
            return $this->result('INCOMPLETE', reason: 'owner_or_expiration');
        }
        foreach (['consumed_at', 'revoked_at'] as $field) {
            if (isset($document[$field]) && ! ($document[$field] instanceof UTCDateTime)) {
                return $this->result('UNKNOWN/CORRUPT', reason: 'invalid_state_timestamp');
            }
        }

        foreach (['code', 'short_code', 'code_hash', 'short_code_hash', 'code_encrypted'] as $field) {
            if (array_key_exists($field, $document) && $document[$field] !== null &&
                (! is_string($document[$field]) || $document[$field] === '')) {
                return $this->result('UNKNOWN/CORRUPT', reason: 'invalid_field_type');
            }
        }

        $code = $document['code'] ?? null;
        $short = $document['short_code'] ?? null;
        $codeHash = $document['code_hash'] ?? null;
        $shortHash = $document['short_code_hash'] ?? null;
        $encrypted = $document['code_encrypted'] ?? null;

        foreach ([$codeHash, $shortHash] as $hash) {
            if ($hash !== null && ! preg_match('/^[0-9a-f]{64}$/', $hash)) {
                return $this->result('UNKNOWN/CORRUPT', reason: 'invalid_hash_format');
            }
        }

        if (($type === 'dynamic' && $encrypted !== null) ||
            ($type === 'identification' && ($short !== null || $shortHash !== null || array_key_exists('short_code_claimed', $document)))) {
            return $this->result('UNKNOWN/CORRUPT', reason: 'unexpected_fields');
        }

        if ($code !== null && $codeHash !== null && ! hash_equals(QrLookupHash::code($code), $codeHash)) {
            return $this->result('HYBRID_INCONSISTENT', reason: 'code_hash_mismatch');
        }
        if ($short !== null && $shortHash !== null && ! hash_equals(QrLookupHash::shortCode($short), $shortHash)) {
            return $this->result('HYBRID_INCONSISTENT', reason: 'short_hash_mismatch');
        }
        if ($encrypted !== null) {
            try {
                $decrypted = Crypt::decryptString($encrypted);
            } catch (DecryptException) {
                return $this->result('HYBRID_INCONSISTENT', reason: 'ciphertext_invalid');
            }
            if (($code !== null && ! hash_equals($code, $decrypted)) ||
                ($codeHash !== null && ! hash_equals($codeHash, QrLookupHash::code($decrypted)))) {
                return $this->result('HYBRID_INCONSISTENT', reason: 'ciphertext_mismatch');
            }
        }

        if ($codeHash !== null && $this->tokens->countDocuments(['code_hash' => $codeHash, '_id' => ['$ne' => $document['_id']]]) > 0) {
            return $this->result('CONFLICT', reason: 'code_hash_collision');
        }

        if ($type === 'dynamic') {
            return $this->analyzeDynamic($document, $code, $short, $codeHash, $shortHash);
        }

        return $this->analyzeIdentification($document, $code, $codeHash, $encrypted);
    }

    private function analyzeDynamic(array $document, ?string $code, ?string $short, ?string $codeHash, ?string $shortHash): array
    {
        if ($code !== null && $short === null) {
            if (! $this->isTerminal($document)) {
                return $this->result('INCOMPLETE', reason: 'missing_legacy_dynamic_secret');
            }
            // A persisted revocation or consumption is terminal in the
            // current validator. Expiration alone is not used as proof.
            // Without a presented short code, a stored short hash or active
            // claim cannot be verified and must not be accepted as prepared.
            if ($shortHash !== null) {
                return $this->result('INCOMPLETE', reason: 'unverifiable_short_hash');
            }
            $claim = $document['short_code_claimed'] ?? null;
            if ($claim !== null && ! is_bool($claim)) {
                return $this->result('UNKNOWN/CORRUPT', reason: 'invalid_claim_type');
            }
            if ($claim === true) {
                return $this->result('CONFLICT', reason: 'terminal_short_code_claim');
            }

            $expectedCodeHash = QrLookupHash::code($code);
            if ($this->tokens->countDocuments(['code_hash' => $expectedCodeHash, '_id' => ['$ne' => $document['_id']]]) > 0) {
                return $this->result('CONFLICT', reason: 'code_hash_collision');
            }

            return $codeHash === null
                ? $this->result('LEGACY_DYNAMIC_TERMINAL_CONVERTIBLE', ['code_hash' => $expectedCodeHash])
                : $this->result('LEGACY_DYNAMIC_TERMINAL_PREPARED');
        }

        if ($code === null && $short === null) {
            if ($codeHash === null || $shortHash === null || ! is_bool($document['short_code_claimed'] ?? null)) {
                return $this->result('INCOMPLETE', reason: 'missing_dynamic_representation');
            }
            if ($this->isActive($document) && $document['short_code_claimed'] !== true) {
                return $this->result('CONFLICT', reason: 'active_unclaimed_short_code');
            }
            return $this->result('NEW_SECURE');
        }

        if ($code === null || $short === null) {
            return $this->result('INCOMPLETE', reason: 'missing_legacy_dynamic_secret');
        }

        $expectedCodeHash = QrLookupHash::code($code);
        $expectedShortHash = QrLookupHash::shortCode($short);
        if ($this->tokens->countDocuments(['code_hash' => $expectedCodeHash, '_id' => ['$ne' => $document['_id']]]) > 0) {
            return $this->result('CONFLICT', reason: 'code_hash_collision');
        }

        $active = $this->isActive($document);
        if ($active) {
            $otherActive = $this->tokens->countDocuments([
                '_id' => ['$ne' => $document['_id']],
                'type' => 'dynamic',
                'consumed_at' => null,
                'revoked_at' => null,
                'expires_at' => ['$gt' => new UTCDateTime((int) now()->format('Uv'))],
                '$or' => [['short_code' => $short], ['short_code_hash' => $expectedShortHash]],
            ]);
            if ($otherActive > 0) {
                return $this->result('CONFLICT', reason: 'active_short_code_collision');
            }
            $otherClaim = $this->tokens->countDocuments([
                '_id' => ['$ne' => $document['_id']],
                'type' => 'dynamic',
                'short_code_hash' => $expectedShortHash,
                'short_code_claimed' => true,
            ]);
            if ($otherClaim > 0) {
                return $this->result('CONFLICT', reason: 'short_code_claim_collision');
            }
        }

        $claim = $document['short_code_claimed'] ?? null;
        if ($claim !== null && ! is_bool($claim)) {
            return $this->result('UNKNOWN/CORRUPT', reason: 'invalid_claim_type');
        }
        if ($claim === true && $this->tokens->countDocuments([
            '_id' => ['$ne' => $document['_id']],
            'type' => 'dynamic',
            'short_code_hash' => $expectedShortHash,
            'short_code_claimed' => true,
        ]) > 0) {
            return $this->result('CONFLICT', reason: 'short_code_claim_collision');
        }

        $updates = [];
        if ($codeHash === null) {
            $updates['code_hash'] = $expectedCodeHash;
        }
        if ($shortHash === null) {
            $updates['short_code_hash'] = $expectedShortHash;
        }
        if ($active && $claim !== true) {
            $updates['short_code_claimed'] = true;
        }

        return $this->result($updates === [] ? 'HYBRID_VALID' : 'LEGACY_DYNAMIC_CONVERTIBLE', $updates);
    }

    private function analyzeIdentification(array $document, ?string $code, ?string $codeHash, ?string $encrypted): array
    {
        if ($code === null) {
            if ($codeHash === null || $encrypted === null) {
                return $this->result('INCOMPLETE', reason: 'missing_identification_representation');
            }
            return $this->result('NEW_SECURE');
        }

        $expectedHash = QrLookupHash::code($code);
        if ($this->tokens->countDocuments(['code_hash' => $expectedHash, '_id' => ['$ne' => $document['_id']]]) > 0) {
            return $this->result('CONFLICT', reason: 'code_hash_collision');
        }

        $updates = [];
        if ($codeHash === null) {
            $updates['code_hash'] = $expectedHash;
        }
        if ($encrypted === null) {
            $ciphertext = Crypt::encryptString($code);
            if (! hash_equals($code, Crypt::decryptString($ciphertext)) ||
                ! hash_equals($expectedHash, QrLookupHash::code(Crypt::decryptString($ciphertext)))) {
                return $this->result('UNKNOWN/CORRUPT', reason: 'encryption_verification_failed');
            }
            $updates['code_encrypted'] = $ciphertext;
        }

        return $this->result($updates === [] ? 'HYBRID_VALID' : 'LEGACY_IDENTIFICATION_CONVERTIBLE', $updates);
    }

    private function isActive(array $document): bool
    {
        $expires = $document['expires_at'] ?? null;
        return ($document['consumed_at'] ?? null) === null
            && ($document['revoked_at'] ?? null) === null
            && $expires instanceof UTCDateTime
            && $expires->toDateTime() > now();
    }

    private function isTerminal(array $document): bool
    {
        return ($document['revoked_at'] ?? null) instanceof UTCDateTime
            || ($document['consumed_at'] ?? null) instanceof UTCDateTime;
    }

    private function result(string $status, array $updates = [], ?string $reason = null): array
    {
        return compact('status', 'updates', 'reason');
    }

    /** Compare-and-set all observed fields plus absent target fields; never save a stale model. */
    public function apply(array $document, array $analysis): bool
    {
        if ($analysis['updates'] === []) {
            return false;
        }

        $filter = [
            '_id' => $document['_id'],
            '$expr' => ['$eq' => [['$size' => ['$objectToArray' => '$$ROOT']], count($document)]],
        ];
        foreach ($document as $field => $value) {
            if ($field !== '_id') {
                // MongoDB equality with null also matches an absent field.
                // Require presence so an unset plus unrelated add cannot
                // masquerade as the same snapshot.
                $filter[$field] = $value === null ? ['$exists' => true, '$eq' => null] : $value;
            }
        }
        foreach (array_keys($analysis['updates']) as $field) {
            if (! array_key_exists($field, $document)) {
                $filter[$field] = ['$exists' => false];
            }
        }

        return $this->tokens->updateOne($filter, ['$set' => $analysis['updates']])->getModifiedCount() === 1;
    }

    /** @return iterable<array> */
    public function documents(int $batch): iterable
    {
        $lastId = null;
        do {
            $filter = $lastId === null ? [] : ['_id' => ['$gt' => $lastId]];
            $page = iterator_to_array($this->tokens->find($filter, ['sort' => ['_id' => 1], 'limit' => $batch]));
            foreach ($page as $raw) {
                $document = (array) $raw;
                $lastId = $document['_id'];
                yield $document;
            }
        } while (count($page) === $batch);
    }
}
