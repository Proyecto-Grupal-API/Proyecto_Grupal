<?php

namespace Tests\Unit;

use App\Exceptions\DirectoryFailure;
use App\Http\Middleware\DirectoryTransport;
use App\Services\Directory\DirectoryAccountCondition as Condition;
use App\Services\Directory\DirectoryInput as Input;
use App\Services\Directory\DirectoryPolicy as Policy;
use App\Services\Directory\DirectoryProjection as Projection;
use App\Services\Directory\DirectoryQuota;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Pure tests: no application, Eloquent models, DB, cache, dotenv, services or file writes. */
final class IdentityDirectoryTest extends TestCase
{
    private function body(array $changes = []): array
    {
        return array_replace(['user_ids' => ['known-id'], 'purpose' => 'historical_reference',
            'context' => ['type' => 'organization', 'id' => 'org-1'],
            'projection' => ['identity', 'account_condition'], 'include_enrollment' => false], $changes);
    }

    private function clients(): array
    {
        return ['fixture-service' => ['daily_reference_limit' => 200,
            'batch' => ['purposes' => ['historical_reference' => [
                'projections' => ['identity', 'account_condition'], 'context_types' => ['organization'], 'enrollment' => true]]],
            'resolve' => ['purposes' => ['membership_management' => [
                'projections' => ['identity'], 'context_types' => ['organization'], 'enrollment' => true]]],
        ]];
    }

    public static function conditions(): iterable
    {
        yield 'legacy missing flags' => [[], 'operational', true];
        yield 'clear flags' => [['account_activation_pending' => false, 'must_change_password' => false], 'operational', true];
        yield 'pending activation' => [['account_activation_pending' => true], 'activation_pending', false];
        yield 'initial password' => [['must_change_password' => true], 'initial_password_change_required', false];
        yield 'both flags' => [['account_activation_pending' => true, 'must_change_password' => true], null, null];
        yield 'bad activation flag' => [['account_activation_pending' => 'false'], null, null];
        yield 'bad initial flag' => [['must_change_password' => []], null, null];
        yield 'baja precedes contradictory flags' => [['deleted_at' => '2026-01-01', 'account_activation_pending' => true, 'must_change_password' => true], 'deleted', false];
        yield 'academic suspended is not account suspension' => [['academic_status' => 'suspended'], 'operational', true];
        yield 'academic inactive is not account inactivity' => [['academic_status' => 'inactive'], 'operational', true];
        yield 'integer legacy boolean' => [['must_change_password' => 1], 'initial_password_change_required', false];
    }

    #[DataProvider('conditions')]
    public function test_condition_is_derived_only_from_account_fields(array $account, ?string $state, ?bool $usable): void
    {
        $condition = Condition::classify($account, false);
        self::assertSame($state, $condition['state'] ?? null);
        self::assertSame($usable, $condition['usable'] ?? null);
        if ($condition !== null && $state !== 'deleted') { self::assertFalse($condition['has_student_profile']); }
    }

    public static function enrollments(): iterable
    {
        yield [' abc-123 ', 'ABC-123'];
        yield ['001', '001'];
        yield ['ABC', 'ABC'];
        yield ['', null]; yield ['A B', null]; yield [['$ne' => null], null];
        yield ['A.*', null]; yield [str_repeat('A', 31), null]; yield [123, null];
    }

    #[DataProvider('enrollments')]
    public function test_enrollment_reuses_exact_normalization(mixed $value, ?string $expected): void
    {
        self::assertSame($expected, Input::enrollment($value));
    }

    public static function invalidBodies(): iterable
    {
        yield 'empty list' => [['user_ids' => []]];
        yield 'too many positions' => [['user_ids' => array_fill(0, 101, 'id')]];
        yield 'associative ids' => [['user_ids' => ['id' => 'person']]];
        yield 'unknown field' => [['actor_id' => 'admin']];
        yield 'open projection' => [['projection' => ['email']]];
        yield 'duplicate projection' => [['projection' => ['identity', 'identity']]];
        yield 'object projection' => [['projection' => [['all' => true]]]];
        yield 'non boolean enrollment' => [['include_enrollment' => 'true']];
        yield 'enrollment with no identity' => [['projection' => ['account_condition'], 'include_enrollment' => true]];
        yield 'unbounded context' => [['context' => ['type' => 'organization', 'id' => 'org', 'actor' => 'root']]];
        yield 'context operator' => [['context' => ['type' => 'organization', 'id' => ['$ne' => null]]]];
        yield 'missing purpose' => [['purpose' => null]];
    }

    #[DataProvider('invalidBodies')]
    public function test_invalid_envelopes_fail_closed(array $changes): void
    {
        $this->expectException(DirectoryFailure::class);
        Input::parse($this->body($changes), 'batch');
    }

    public function test_batch_retains_positions_and_does_not_promote_query_operators_to_ids(): void
    {
        $input = Input::parse($this->body(['user_ids' => ['id', 'id', ['$ne' => null], '', 1]]), 'batch');
        self::assertSame(5, $input['reference_count']);
        self::assertSame(['id', 'id', ['$ne' => null], '', 1], $input['user_ids']);
        foreach ([2, 3, 4] as $index) {
            $item = Projection::item($index, $input['user_ids'][$index], null, [], $input);
            self::assertSame('invalid_id', $item['result']);
            self::assertNull($item['requested_user_id']);
        }
    }

    public function test_only_explicit_client_purpose_context_and_projections_authorize(): void
    {
        $input = Input::parse($this->body(['include_enrollment' => true]), 'batch');
        $profile = Policy::authorize('fixture-service', [Policy::READ, Policy::CONDITION, Policy::ENROLLMENT], $input, $this->clients());
        self::assertSame(200, $profile['daily_reference_limit']);
        self::assertSame([Policy::READ, Policy::CONDITION, Policy::ENROLLMENT], Policy::scopes($input));
    }

    public static function deniedPolicies(): iterable
    {
        yield 'unknown client' => ['client']; yield 'empty allowlist' => ['allowlist'];
        yield 'scope absent' => ['scopes']; yield 'purpose not approved' => ['purpose'];
        yield 'context not approved' => ['context']; yield 'projection not approved' => ['projection'];
        yield 'enrollment not approved' => ['enrollment']; yield 'daily budget absent' => ['budget'];
    }

    #[DataProvider('deniedPolicies')]
    public function test_policy_has_no_role_admin_or_context_fallback(string $case): void
    {
        $clients = $this->clients(); $body = $this->body(['include_enrollment' => true]);
        $scopes = [Policy::READ, Policy::CONDITION, Policy::ENROLLMENT]; $client = 'fixture-service';
        if ($case === 'client') { $client = 'admin'; }
        if ($case === 'allowlist') { $clients = []; }
        if ($case === 'scopes') { $scopes = ['students:read']; }
        if ($case === 'purpose') { $body['purpose'] = 'office_assignment'; }
        if ($case === 'context') { $body['context']['type'] = 'event'; }
        if ($case === 'projection') { $clients[$client]['batch']['purposes']['historical_reference']['projections'] = ['identity']; }
        if ($case === 'enrollment') { $clients[$client]['batch']['purposes']['historical_reference']['enrollment'] = false; }
        if ($case === 'budget') { unset($clients[$client]['daily_reference_limit']); }
        $this->expectException(DirectoryFailure::class);
        Policy::authorize($client, $scopes, Input::parse($body, 'batch'), $clients);
    }

    public function test_resolve_scope_is_not_a_general_directory_or_academic_scope(): void
    {
        $input = Input::parse(['enrollment_number' => ' abc-1 ', 'purpose' => 'membership_management',
            'context' => ['type' => 'organization', 'id' => 'org-1']], 'resolve');
        self::assertSame('ABC-1', $input['enrollment_number']);
        self::assertSame([Policy::RESOLVE], Policy::scopes($input));
        self::assertSame(200, Policy::authorize('fixture-service', [Policy::RESOLVE], $input, $this->clients())['daily_reference_limit']);
    }

    public function test_projection_never_serializes_private_fields_or_invents_a_student_profile(): void
    {
        $input = Input::parse($this->body(), 'batch');
        $account = ['name' => 'Synthetic name', 'email' => 'private', 'password' => 'private', 'roles' => ['admin'], 'two_factor_secret' => 'private'];
        $item = Projection::item(0, 'id', $account, [], $input);
        self::assertSame(['user_id' => 'id', 'display_name' => 'Synthetic name'], $item['identity']);
        self::assertSame(['state' => 'operational', 'usable' => true, 'has_student_profile' => false], $item['account_condition']);
        self::assertStringNotContainsString('private', json_encode($item));
    }

    public function test_enrollment_is_absent_unless_requested_and_null_if_no_profile(): void
    {
        $input = Input::parse($this->body(['include_enrollment' => true]), 'batch');
        self::assertNull(Projection::item(0, 'id', ['name' => 'Test'], [], $input)['identity']['enrollment_number']);
        self::assertSame('ABC-1', Projection::item(0, 'id', ['name' => 'Test'], [['enrollment_number' => 'ABC-1']], $input)['identity']['enrollment_number']);
        $input['include_enrollment'] = false;
        self::assertArrayNotHasKey('enrollment_number', Projection::item(0, 'id', ['name' => 'Test'], [], $input)['identity']);
    }

    public function test_deleted_identity_is_redacted_even_with_all_projections(): void
    {
        $input = Input::parse($this->body(['include_enrollment' => true]), 'batch');
        $account = ['name' => 'Private old name', 'deleted_at' => 'date'];
        $item = Projection::item(0, 'id', $account, [['enrollment_number' => 'PRIVATE']], $input);
        self::assertSame('deleted', $item['result']); self::assertNull($item['identity']);
        self::assertNull($item['account_condition']['has_student_profile']);
        $input['projection'] = ['identity'];
        self::assertSame('not_found', Projection::item(0, 'id', $account, [], $input)['result']);
    }

    public function test_missing_and_inconsistent_records_are_not_successful_identities(): void
    {
        $input = Input::parse($this->body(['include_enrollment' => true]), 'batch');
        self::assertSame('not_found', Projection::item(0, 'id', null, [], $input)['result']);
        self::assertSame('unavailable', Projection::item(0, 'id', ['name' => 'Test', 'account_activation_pending' => true, 'must_change_password' => true], [], $input)['result']);
        self::assertSame('unavailable', Projection::item(0, 'id', ['name' => 'Test'], [['enrollment_number' => 'lower']], $input)['result']);
        self::assertSame('unavailable', Projection::item(0, 'id', ['name' => 'Test'], [[], []], $input)['result']);
    }

    public function test_condition_only_does_not_release_name_or_enrollment(): void
    {
        $input = Input::parse($this->body(['projection' => ['account_condition']]), 'batch');
        $item = Projection::item(0, 'id', ['name' => 'Private'], [], $input);
        self::assertArrayNotHasKey('identity', $item);
        self::assertSame([Policy::CONDITION], Policy::scopes($input));
    }

    public function test_quota_cost_counts_positions_not_unique_ids_and_has_a_daily_bound(): void
    {
        $limits = DirectoryQuota::limits('batch', 100, 200);
        self::assertSame([30, 60, 1], $limits['requests:batch']);
        self::assertSame([3000, 60, 100], $limits['references:minute']);
        self::assertSame([200, 86400, 100], $limits['references:day']);
    }

    public function test_quota_without_positive_daily_budget_is_not_enabled(): void
    {
        $this->expectException(DirectoryFailure::class);
        DirectoryQuota::limits('batch', 1, 0);
    }

    public static function errors(): iterable
    {
        yield [new DirectoryFailure(503, 'directory_audit_unavailable'), 503];
        yield [new DirectoryFailure(429, 'directory_rate_limited', 60), 429];
        yield [new RuntimeException('private-user private-enrollment private-password'), 500];
        yield [new \MongoDB\Driver\Exception\ConnectionException('private-connection'), 503];
        yield [new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('Bearer', 'private token'), 401];
        yield [new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('private grants'), 403];
    }

    #[DataProvider('errors')]
    public function test_transport_errors_are_private_and_have_no_store_headers(\Throwable $error, int $status): void
    {
        $response = DirectoryTransport::failure(Request::create('/api/v1/identity/directory/batch'), $error);
        self::assertSame($status, $response->getStatusCode());
        self::assertStringNotContainsString('private', $response->getContent());
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertArrayHasKey('request_id', json_decode($response->getContent(), true)['meta']);
    }

    public function test_body_limit_rejects_before_delegating_without_an_application(): void
    {
        $request = Request::create('/api/v1/identity/directory/batch', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: str_repeat('x', 32769));
        $response = (new DirectoryTransport)->handle($request, fn () => throw new RuntimeException('Must not reach auth/query'));
        self::assertSame(413, $response->getStatusCode());
    }

    public function test_response_limit_does_not_release_an_oversized_payload(): void
    {
        $request = Request::create('/api/v1/identity/directory/batch', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        $response = (new DirectoryTransport)->handle($request, fn () => new JsonResponse(['name' => str_repeat('x', 131073)]));
        self::assertSame(503, $response->getStatusCode());
        self::assertLessThan(1024, strlen($response->getContent()));
    }

    public function test_raw_references_are_not_trimmed_or_replaced_by_global_request_normalization(): void
    {
        $request = Request::create('/api/v1/identity/directory/batch', 'POST', server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"user_ids":[" id ",""],"purpose":"historical_reference"}');
        (new DirectoryTransport)->handle($request, function ($request) {
            self::assertSame([' id ', ''], $request->attributes->get('directory_payload')['user_ids']);

            return new JsonResponse(['ok' => true]);
        });
    }

    public function test_directory_does_not_accept_query_filters(): void
    {
        $request = Request::create('/api/v1/identity/directory/batch?search=someone', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        $response = (new DirectoryTransport)->handle($request, fn () => throw new RuntimeException('Must not query'));
        self::assertSame(422, $response->getStatusCode());
    }
}
