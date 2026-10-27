<?php

namespace Tests\Unit;

use App\Domains\Financial\Adapters\IdentityBonusAuthorizationProvider;
use App\Domains\Financial\Data\BonusIssuerContext;
use App\Domains\Financial\Enums\BonusType;
use App\Domains\Financial\Exceptions\IdentityFinancialAuthorizationUnavailable;
use App\Domains\Financial\Integrations\Identity\IdentityFinancialAuthorizationClient;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pure HTTP-contract tests. No Laravel boot, database, real credentials or network. */
final class IdentityFinancialAuthorizationClientTest extends TestCase
{
    private mixed $previousFacadeApp;

    private Container $previousContainer;

    private IdentityFinancialAuthorizationClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousFacadeApp = Facade::getFacadeApplication();
        $this->previousContainer = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(['app' => ['env' => 'testing'],
            'financial_identity_authorization' => ['enabled' => true, 'origin' => 'http://127.0.0.1:8086',
                'client_id' => 'fixture-finance-client', 'client_secret' => 'fixture-client-secret']]));
        $container->instance('log', new \Illuminate\Log\Logger(new Logger('fixture-identity-authorization')));
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->client = new IdentityFinancialAuthorizationClient;
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApp);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    private function token(): array
    {
        return ['access_token' => 'fixture-access-token', 'token_type' => 'Bearer', 'expires_in' => 3600,
            'scope' => 'identity:financial-authorization:check'];
    }

    private function fake(mixed $decision = true, int $status = 200): void
    {
        Http::fake([
            'http://127.0.0.1:8086/api/oauth/token' => Http::response($this->token()),
            'http://127.0.0.1:8086/api/v1/identity/financial-authorization/check' => Http::response(['authorized' => $decision], $status),
        ]);
    }

    public static function bonusTypes(): array
    {
        return [['BECA'], ['CAMPANIA'], ['BENEFICIO'], ['CATEGORIA'], ['NEGOCIO']];
    }

    #[DataProvider('bonusTypes')]
    public function test_five_types_send_exact_global_contract_and_service_oauth(string $type): void
    {
        $this->fake();
        self::assertTrue($this->client->check('507F1F77BCF86CD799439011', BonusType::from($type)));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/oauth/token') && $r->data() === [
            'grant_type' => 'client_credentials', 'client_id' => 'fixture-finance-client',
            'client_secret' => 'fixture-client-secret', 'scope' => 'identity:financial-authorization:check']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/financial-authorization/check')
            && $r->hasHeader('Authorization', 'Bearer fixture-access-token')
            && $r->data() === ['subject_id' => '507f1f77bcf86cd799439011', 'bonus_type' => $type,
                'operation' => 'issue', 'scope_type' => null, 'scope_id' => null]);
        Http::assertSentCount(2);
    }

    public function test_explicit_false_is_a_denial_not_a_dependency_error(): void
    {
        $this->fake(false);
        self::assertFalse($this->client->check('507f1f77bcf86cd799439011', BonusType::BECA));
    }

    public function test_negocio_context_uses_one_exact_scope_and_no_global_fallback(): void
    {
        $this->fake(false);
        self::assertFalse($this->client->check('507f1f77bcf86cd799439011', BonusType::NEGOCIO, new BonusIssuerContext('business', 'business-A')));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/financial-authorization/check')
            && $r['scope_type'] === 'business' && $r['scope_id'] === 'business-A');
        Http::assertSentCount(2);
    }

    public static function invalidScopes(): array
    {
        return [['BECA', 'business'], ['CAMPANIA', 'business'], ['BENEFICIO', 'business'],
            ['CATEGORIA', 'business'], ['NEGOCIO', 'association'], ['NEGOCIO', 'service'], ['NEGOCIO', 'council']];
    }

    #[DataProvider('invalidScopes')]
    public function test_unsupported_scopes_fail_before_http(string $type, string $scope): void
    {
        try {
            $this->client->check('507f1f77bcf86cd799439011', BonusType::from($type), new BonusIssuerContext($scope, 'context-A'));
            self::fail('Expected invalid context');
        } catch (InvalidArgumentException) {
            Http::assertNothingSent();
        }
    }

    public static function nonBooleans(): array
    {
        return [['true'], ['false'], [1], [0], [null], [[]], [(object) ['allow' => true]]];
    }

    #[DataProvider('nonBooleans')]
    public function test_truthy_or_malformed_values_never_authorize(mixed $value): void
    {
        $this->fake($value);
        $this->expectException(IdentityFinancialAuthorizationUnavailable::class);
        $this->client->check('507f1f77bcf86cd799439011', BonusType::BECA);
    }

    public static function upstreamErrors(): array
    {
        return [[401], [403], [404], [409], [422], [429], [500], [503], [302], [201]];
    }

    #[DataProvider('upstreamErrors')]
    public function test_errors_at_check_are_unavailable_even_when_body_says_true(int $status): void
    {
        $this->fake(true, $status);
        try {
            $this->client->check('507f1f77bcf86cd799439011', BonusType::BENEFICIO);
            self::fail('Expected unavailable');
        } catch (IdentityFinancialAuthorizationUnavailable $e) {
            self::assertSame($status, $e->upstreamStatus);
            self::assertSame('CHECK_HTTP_'.$status, $e->reasonCode);
            self::assertStringNotContainsString('fixture', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
        Http::assertSentCount(2);
    }

    public function test_absent_business_validator_is_unavailable_without_global_fallback(): void
    {
        $this->fake(true, 503);
        $this->expectException(IdentityFinancialAuthorizationUnavailable::class);
        try {
            $this->client->check('507f1f77bcf86cd799439011', BonusType::NEGOCIO, new BonusIssuerContext('business', 'business-A'));
        } finally {
            Http::assertSentCount(2);
        }
    }

    public function test_revocation_is_rechecked_without_a_positive_cache(): void
    {
        Http::fake([
            'http://127.0.0.1:8086/api/oauth/token' => Http::response($this->token()),
            'http://127.0.0.1:8086/api/v1/identity/financial-authorization/check' => Http::sequence()
                ->push(['authorized' => true])->push(['authorized' => false]),
        ]);
        self::assertTrue($this->client->check('507f1f77bcf86cd799439011', BonusType::BECA));
        self::assertFalse($this->client->check('507f1f77bcf86cd799439011', BonusType::BECA));
        Http::assertSentCount(4);
    }

    public static function unsafeOrigins(): array
    {
        return [['http://external.invalid'], ['https://user:pass@identity.invalid'], ['https://identity.invalid/api'],
            ['https://identity.invalid?secret=x'], ['https://identity.invalid/#fragment'], ['file:///tmp']];
    }

    #[DataProvider('unsafeOrigins')]
    public function test_invalid_origin_stops_before_credentials_are_sent(string $origin): void
    {
        config(['financial_identity_authorization.origin' => $origin]);
        try {
            $this->client->check('507f1f77bcf86cd799439011', BonusType::BECA);
            self::fail('Expected invalid config');
        } catch (IdentityFinancialAuthorizationUnavailable $e) {
            self::assertSame('INVALID_ORIGIN', $e->reasonCode);
        }
        Http::assertNothingSent();
    }

    public function test_production_refuses_http_loopback_and_accepts_no_disabled_override(): void
    {
        config(['app.env' => 'production']);
        try {
            $this->client->check('507f1f77bcf86cd799439011', BonusType::BECA);
            self::fail('Expected TLS requirement');
        } catch (IdentityFinancialAuthorizationUnavailable $e) {
            self::assertSame('INVALID_ORIGIN', $e->reasonCode);
        }
        config(['financial_identity_authorization.enabled' => false]);
        try {
            $this->client->check('507f1f77bcf86cd799439011', BonusType::BECA);
            self::fail('Expected disabled');
        } catch (IdentityFinancialAuthorizationUnavailable $e) {
            self::assertSame('INTEGRATION_DISABLED', $e->reasonCode);
        }
        Http::assertNothingSent();
    }

    public function test_oauth_failure_does_not_consult_authorization_or_leak_upstream_body(): void
    {
        Http::fake(['*' => Http::response(['message' => 'fixture-client-secret'], 401)]);
        try {
            $this->client->check('507f1f77bcf86cd799439011', BonusType::BECA);
            self::fail('Expected OAuth error');
        } catch (IdentityFinancialAuthorizationUnavailable $e) {
            self::assertSame('OAUTH_HTTP_401', $e->reasonCode);
            self::assertStringNotContainsString('fixture-client-secret', $e->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_missing_oauth_scope_stops_before_check(): void
    {
        $token = $this->token();
        $token['scope'] = 'identity:authorization:check';
        Http::fake(['*' => Http::response($token)]);
        $this->expectException(IdentityFinancialAuthorizationUnavailable::class);
        try {
            $this->client->check('507f1f77bcf86cd799439011', BonusType::BECA);
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_connection_failure_never_authorizes_and_never_retries_automatically(): void
    {
        $calls = 0;
        Http::fake(function ($r) use (&$calls) {
            $calls++;
            if (str_ends_with($r->url(), '/api/oauth/token')) {
                return Http::response($this->token());
            }
            throw new ConnectionException('fixture-access-token fixture-client-secret');
        });
        try {
            $this->client->check('507f1f77bcf86cd799439011', BonusType::BECA);
            self::fail('Expected network error');
        } catch (IdentityFinancialAuthorizationUnavailable $e) {
            self::assertSame('CHECK_NETWORK_FAILURE', $e->reasonCode);
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString('fixture', $e->getMessage());
        }
        self::assertSame(2, $calls);
    }

    public static function malformedBodies(): array
    {
        return [['<html>true</html>', 'text/html'], ['{"authorized":true', 'application/json'],
            ['{}', 'application/json'], ['true', 'application/json'], ['[]', 'application/json'],
            ['{"authorized":true}', 'text/plain'], [str_repeat(' ', 8200).'{}', 'application/json']];
    }

    #[DataProvider('malformedBodies')]
    public function test_invalid_decision_envelopes_are_unavailable(string $body, string $contentType): void
    {
        Http::fake([
            'http://127.0.0.1:8086/api/oauth/token' => Http::response($this->token()),
            'http://127.0.0.1:8086/api/v1/identity/financial-authorization/check' => Http::response($body, 200, ['Content-Type' => $contentType]),
        ]);
        $this->expectException(IdentityFinancialAuthorizationUnavailable::class);
        $this->client->check('507f1f77bcf86cd799439011', BonusType::BECA);
    }

    public function test_adapter_rejects_system_actors_and_invalid_or_prefixed_ids_without_http(): void
    {
        $adapter = new IdentityBonusAuthorizationProvider($this->client);
        self::assertFalse($adapter->canIssueBonus('SYSTEM', '507f1f77bcf86cd799439011', BonusType::BECA));
        self::assertFalse($adapter->canIssueBonus('USER', 'user:507f1f77bcf86cd799439011', BonusType::BECA));
        self::assertFalse($adapter->canIssueBonus('USER', 'unknown', BonusType::BECA));
        Http::assertNothingSent();
    }

    public function test_global_context_object_and_null_have_identical_null_null_payload(): void
    {
        $this->fake();
        self::assertTrue((new IdentityBonusAuthorizationProvider($this->client))->canIssueInContext(
            'USER', '507f1f77bcf86cd799439011', BonusType::BENEFICIO, new BonusIssuerContext));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/financial-authorization/check')
            && array_key_exists('scope_type', $r->data()) && $r['scope_type'] === null && $r['scope_id'] === null);
    }

    public function test_report_logs_only_internal_reason_and_status_not_secrets(): void
    {
        Log::shouldReceive('warning')->once()->with('Identity financial authorization unavailable.', [
            'reason_code' => 'CHECK_HTTP_503', 'upstream_status' => 503,
        ]);
        self::assertTrue((new IdentityFinancialAuthorizationUnavailable('CHECK_HTTP_503', 503))->report());
        \Mockery::close();
    }
}
