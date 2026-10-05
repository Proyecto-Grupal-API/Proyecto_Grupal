<?php

namespace App\Console\Commands;

use App\Services\LegacyRoleBackfillService;
use App\Services\LegacyRoleParityService;
use Illuminate\Console\Command;

class BackfillLegacyRoleAssignments extends Command
{
    protected $signature = 'identity:backfill-role-assignments {--dry-run} {--apply} {--user=}';
    protected $description = 'Compare legacy roles with shadow assignments; default is non-mutating dry-run.';

    public function handle(LegacyRoleBackfillService $backfill, LegacyRoleParityService $parity): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Choose either --dry-run or --apply.');

            return self::FAILURE;
        }
        try {
            $user = $this->option('user');
            if ($user !== null && (! is_string($user) || ! preg_match('/^[a-fA-F0-9]{24}$/', $user))) {
                $this->error('Invalid internal user identifier.');

                return self::FAILURE;
            }
            $report = $backfill->run((bool) $this->option('apply'), $user);
            $report['parity'] = $parity->report($user);
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return ($report['conflicts'] || ($user !== null && ! $report['users_scanned'])
                || $report['parity']['mismatches']) ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Backfill stopped safely. Verify environment, indexes and database availability; no driver values are exposed.');

            return self::FAILURE;
        }
    }
}
