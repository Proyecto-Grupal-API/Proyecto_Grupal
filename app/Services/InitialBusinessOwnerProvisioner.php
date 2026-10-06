<?php

namespace App\Services;

use App\Models\BusinessMembership;
use App\Models\BusinessOwnerClaim;
use App\Models\BusinessOwnerProvision;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use MongoDB\Driver\Exception\CommandException;
use MongoDB\Driver\Exception\Exception;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Special trusted service operation; does not delegate any human role-management authority. */
final class InitialBusinessOwnerProvisioner
{
    public function __construct(private BusinessAuthorizationService $authorization) {}

    public function provision(string $client, string $subject, string $business, string $operation): bool
    {
        $this->assertSchema();
        // Unique-key races are resolved only after the losing transaction has rolled back.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::connection('mongodb')->transaction(function () use ($client, $subject, $business, $operation) {
                    $receipt = BusinessOwnerProvision::where('operation_id', $operation)->first();
                    if ($receipt) {
                        $this->assertRetry($receipt, $client, $subject, $business);

                        return false;
                    }
                    $user = $this->authorization->validUser(User::whereKey($subject)->first());
                    abort_unless($user, 422, 'The requested subject is not eligible for provisioning.');
                    $claim = BusinessOwnerClaim::where('business_id', $business)->first();
                    if ($claim && $claim->subject_id !== $subject) {
                        $this->conflict();
                    }
                    // Include non-active/history too: initial provisioning never re-grants ownership.
                    $owners = RoleAssignment::where('role_key', 'business_owner')
                        ->where('scope_type', 'business')->where('scope_id', $business)->get();
                    if ($owners->contains(fn ($owner) => $owner->user_id !== $subject)
                        || $owners->count() > 1) {
                        $this->conflict();
                    }
                    $owner = $owners->first();
                    if ($owner && (! $owner->is_current || $owner->status !== 'active'
                        || ($owner->starts_at && $owner->starts_at->isFuture())
                        || ($owner->ends_at && ! $owner->ends_at->isFuture()))) {
                        $this->conflict();
                    }
                    $memberships = BusinessMembership::where('user_id', $subject)->where('business_id', $business)->get();
                    $membership = $memberships->firstWhere('is_current', true);
                    if ($memberships->isNotEmpty() && (! $membership || $membership->status !== 'active'
                        || ! $this->authorization->hasMembership($user, $business))) {
                        // Pending, suspended, revoked and expired states require their own lifecycle workflow.
                        $this->conflict();
                    }
                    if (! $claim) {
                        BusinessOwnerClaim::create(['business_id' => $business, 'subject_id' => $subject]);
                    }
                    if (! $membership) {
                        BusinessMembership::create(['user_id' => $subject, 'business_id' => $business, 'status' => 'active']);
                    }
                    if (! $owner) {
                        RoleAssignment::create(['user_id' => $subject, 'role_key' => 'business_owner',
                            'scope_type' => 'business', 'scope_id' => $business, 'status' => 'active',
                            'origin' => 'initial_owner_provision']);
                    }
                    BusinessOwnerProvision::create(['operation_id' => $operation, 'business_id' => $business,
                        'subject_id' => $subject, 'client_id' => $client, 'result' => 'provisioned']);

                    return true;
                }, 5);
            } catch (Exception $error) {
                if ($error->getCode() !== 11000) {
                    throw $error;
                }
                $receipt = BusinessOwnerProvision::where('operation_id', $operation)->first();
                if ($receipt) {
                    $this->assertRetry($receipt, $client, $subject, $business);

                    return false;
                }
                $claim = BusinessOwnerClaim::where('business_id', $business)->first();
                if (! $claim || $claim->subject_id !== $subject) {
                    $this->conflict();
                }
            }
        }
        $this->conflict();
    }

    private function assertRetry(BusinessOwnerProvision $receipt, string $client, string $subject, string $business): void
    {
        if ($receipt->client_id !== $client || $receipt->subject_id !== $subject || $receipt->business_id !== $business) {
            $this->conflict();
        }
    }

    private function conflict(): never
    {
        throw new ConflictHttpException('Initial owner provisioning conflicts with existing state.');
    }

    private function assertSchema(): void
    {
        $required = [
            ['business_owner_claims', 'initial_business_owner_unique', ['business_id' => 1], []],
            ['business_owner_provisions', 'owner_operation_unique', ['operation_id' => 1], []],
        ];
        foreach (['role_assignments' => ['user_id' => 1, 'role_key' => 1, 'scope_type' => 1, 'scope_id' => 1],
            'business_memberships' => ['user_id' => 1, 'business_id' => 1]] as $collection => $identity) {
            $required[] = [$collection, 'current_identity_unique', $identity, ['is_current' => true]];
            $required[] = [$collection, 'identity_generation_unique', [...$identity, 'generation' => 1], []];
        }
        foreach ($required as [$collection, $name, $keys, $partial]) {
            $valid = false;
            try {
                $indexes = iterator_to_array(DB::connection('mongodb')->getCollection($collection)->listIndexes());
            } catch (CommandException $error) {
                if ($error->getCode() !== 26) {
                    throw $error;
                }
                abort(503, 'Owner provisioning schema is not ready.');
            }
            foreach ($indexes as $index) {
                if ($index->getName() === $name && $index->getKey() === $keys && $index->isUnique()
                    && (array) ($index['partialFilterExpression'] ?? []) === $partial && ! $index->isSparse()) {
                    $valid = true;
                }
            }
            abort_unless($valid, 503, 'Owner provisioning schema is not ready.');
        }
    }
}
