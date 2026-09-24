<?php

namespace App\Console\Commands;

use App\Services\QrLegacySecretBackfill;
use Illuminate\Console\Command;
use MongoDB\Driver\Exception\BulkWriteException;
use Throwable;

class BackfillQrSecrets extends Command
{
    protected $signature = 'qr:secrets-backfill
        {--dry-run : Inspect without writing (also the default)}
        {--apply : Explicitly write only missing secure representations}
        {--verify : Read-only readiness check for QR-B.4B}
        {--batch=100 : Maximum documents fetched per page (1-10000)}';

    protected $description = 'Inventory and non-destructively prepare legacy QR secrets';

    public function handle(QrLegacySecretBackfill $backfill): int
    {
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT);
        if ($batch === false || $batch < 1 || $batch > 10000 ||
            ((bool) $this->option('apply') && ((bool) $this->option('dry-run') || (bool) $this->option('verify')))) {
            $this->error('Use --batch=1..10000 and choose only one mode. Writing requires --apply.');
            return self::INVALID;
        }

        $mode = $this->option('verify') ? 'VERIFY' : ($this->option('apply') ? 'APPLY' : 'DRY_RUN');
        $counts = array_fill_keys([
            'scanned', 'already_secure', 'legacy_dynamic_convertible',
            'legacy_dynamic_terminal_convertible', 'legacy_dynamic_terminal_prepared',
            'legacy_identification_convertible', 'hybrid_valid', 'converted',
            'inconsistent', 'incomplete', 'conflicts', 'errors',
        ], 0);

        try {
            foreach ($backfill->documents($batch) as $document) {
                $counts['scanned']++;
                try {
                    $analysis = $backfill->analyze($document);
                    $counter = match ($analysis['status']) {
                        'NEW_SECURE' => 'already_secure',
                        'LEGACY_DYNAMIC_CONVERTIBLE' => 'legacy_dynamic_convertible',
                        'LEGACY_DYNAMIC_TERMINAL_CONVERTIBLE' => 'legacy_dynamic_terminal_convertible',
                        'LEGACY_DYNAMIC_TERMINAL_PREPARED' => 'legacy_dynamic_terminal_prepared',
                        'LEGACY_IDENTIFICATION_CONVERTIBLE' => 'legacy_identification_convertible',
                        'HYBRID_VALID' => 'hybrid_valid',
                        'HYBRID_INCONSISTENT' => 'inconsistent',
                        'INCOMPLETE', 'UNKNOWN/CORRUPT' => 'incomplete',
                        default => 'conflicts',
                    };
                    $counts[$counter]++;

                    if ($mode !== 'APPLY' || $analysis['updates'] === []) {
                        continue;
                    }
                    if (! $backfill->apply($document, $analysis)) {
                        $counts['conflicts']++;
                        continue;
                    }

                    $fresh = $backfill->collection()->findOne(['_id' => $document['_id']]);
                    $verified = $fresh === null ? null : $backfill->analyze((array) $fresh);
                    if ($verified === null || ! in_array($verified['status'], ['HYBRID_VALID', 'LEGACY_DYNAMIC_TERMINAL_PREPARED'], true)) {
                        $counts['errors']++;
                        continue;
                    }
                    $counts['converted']++;
                } catch (BulkWriteException $exception) {
                    if ($exception->getCode() === 11000) {
                        $counts['conflicts']++;
                    } else {
                        $counts['errors']++;
                    }
                } catch (Throwable) {
                    // Never print driver exceptions: their messages may contain query values.
                    $counts['errors']++;
                }
            }
        } catch (Throwable) {
            $counts['errors']++;
        }

        $ready = $counts['errors'] === 0;
        try {
            foreach ($backfill->documents($batch) as $document) {
                $status = $backfill->analyze($document)['status'];
                if (! in_array($status, ['NEW_SECURE', 'HYBRID_VALID', 'LEGACY_DYNAMIC_TERMINAL_PREPARED'], true)) {
                    $ready = false;
                }
            }
        } catch (Throwable) {
            $counts['errors']++;
            $ready = false;
        }

        $this->line('mode='.$mode);
        foreach ($counts as $label => $count) {
            $this->line($label.'='.$count);
        }
        $this->line('READY='.($ready ? 'true' : 'false'));

        return $mode === 'VERIFY' && ! $ready || $counts['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
