<?php

namespace Tests\Unit;

use App\Contracts\BonusAuthorization;
use App\Contracts\FinancialAuthorizationDecision;
use App\Contracts\FinancialContextValidator;
use App\Enums\FinancialContextValidation;
use App\Exceptions\FinancialAuthorizationUnavailable;
use App\Services\FinancialAuthorizationPolicy;
use App\Services\FinancialAuthorizationService;
use Closure;
use Illuminate\Container\Container;
use LogicException;
use MongoDB\Driver\Exception\ConnectionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pure orchestration tests. No Laravel application, models, configuration file or database bootstrap. */
final class FinancialAuthorizationPolicyTest extends TestCase
{
    private function engine(Closure $answer, array &$calls): BonusAuthorization
    {
        return new class($answer, $calls) implements BonusAuthorization
        {
            private array $calls;

            public function __construct(private Closure $answer, array &$calls) { $this->calls = &$calls; }

            public function allows(?string $subjectId, string $bonusType, string $operation, ?string $scopeType = null, ?string $scopeId = null): bool
            {
                $args = func_get_args();
                $this->calls[] = $args;

                return ($this->answer)(...$args);
            }
        };
    }

    private function validator(Closure $answer): FinancialContextValidator
    {
        return new class($answer) implements FinancialContextValidator
        {
            public function __construct(private Closure $answer) {}

            public function validate(string $scopeType, string $scopeId): FinancialContextValidation
            {
                return ($this->answer)($scopeType, $scopeId);
            }
        };
    }

    public static function globalCases(): iterable
    {
        foreach (['BECA', 'CAMPANIA', 'BENEFICIO', 'CATEGORIA', 'NEGOCIO'] as $type) {
            foreach ([true, false] as $decision) {
                yield $type.' '.($decision ? 'allow' : 'deny') => [$type, $decision];
            }
        }
    }

    #[DataProvider('globalCases')]
    public function test_global_queries_delegate_only_to_the_exact_global_branch(string $type, bool $decision): void
    {
        $calls = [];
        $policy = new FinancialAuthorizationPolicy($this->engine(fn () => $decision, $calls), $this->validator(fn () => throw new LogicException('Global must not validate a context')));
        self::assertSame($decision, $policy->allows('subject', $type, 'issue'));
        self::assertSame([['subject', $type, 'issue', null, null]], $calls);
    }

    public function test_context_is_validated_before_global_and_original_parameters_are_preserved(): void
    {
        $calls = []; $trace = [];
        $policy = new FinancialAuthorizationPolicy($this->engine(function ($subject, $type, $operation, $scope, $id) use (&$trace) {
            $trace[] = $scope === null ? 'global' : 'exact';

            return $scope === 'business' && $id === 'Business-A';
        }, $calls), $this->validator(function ($scope, $id) use (&$trace) {
            self::assertSame(['business', 'Business-A'], [$scope, $id]);
            $trace[] = 'context';

            return FinancialContextValidation::Valid;
        }));
        self::assertTrue($policy->allows('Subject-A', 'NEGOCIO', 'issue', 'business', 'Business-A'));
        self::assertSame(['context', 'global', 'exact'], $trace);
        self::assertSame(['Subject-A', 'NEGOCIO', 'issue', 'business', 'Business-A'], $calls[1]);
    }

    public function test_explicit_global_grant_can_authorize_only_after_context_validation(): void
    {
        $calls = []; $validated = false;
        $policy = new FinancialAuthorizationPolicy($this->engine(function () use (&$validated) { self::assertTrue($validated); return true; }, $calls),
            $this->validator(function () use (&$validated) { $validated = true; return FinancialContextValidation::Valid; }));
        self::assertTrue($policy->allows('subject', 'NEGOCIO', 'issue', 'business', 'A'));
        self::assertCount(1, $calls);
    }

    public function test_other_business_does_not_inherit_a_contextual_grant(): void
    {
        $calls = [];
        $policy = new FinancialAuthorizationPolicy($this->engine(fn ($u, $t, $op, $scope, $id) => $scope === 'business' && $id === 'A', $calls),
            $this->validator(fn () => FinancialContextValidation::Valid));
        self::assertFalse($policy->allows('subject', 'NEGOCIO', 'issue', 'business', 'B'));
        self::assertCount(2, $calls);
    }

    public function test_invalid_context_never_consults_global_authority(): void
    {
        $calls = [];
        $policy = new FinancialAuthorizationPolicy($this->engine(fn () => true, $calls), $this->validator(fn () => FinancialContextValidation::Invalid));
        self::assertFalse($policy->allows('subject', 'NEGOCIO', 'issue', 'business', 'missing'));
        self::assertSame([], $calls);
    }

    public function test_missing_validator_is_unavailable_without_evaluating_permissions(): void
    {
        $calls = [];
        $policy = new FinancialAuthorizationPolicy($this->engine(fn () => true, $calls));
        try { $policy->allows('subject', 'NEGOCIO', 'issue', 'business', 'A'); self::fail('Expected unavailable'); }
        catch (FinancialAuthorizationUnavailable $e) { self::assertSame('Authorization infrastructure unavailable.', $e->getMessage()); }
        self::assertSame([], $calls);
    }

    public function test_unavailable_context_never_evaluates_global_authority(): void
    {
        $calls = [];
        $policy = new FinancialAuthorizationPolicy($this->engine(fn () => true, $calls), $this->validator(fn () => FinancialContextValidation::Unavailable));
        try { $policy->allows('subject', 'NEGOCIO', 'issue', 'business', 'A'); self::fail('Expected unavailable'); }
        catch (FinancialAuthorizationUnavailable) { self::assertSame([], $calls); }
    }

    public static function blockedInputs(): iterable
    {
        yield 'beca program' => ['subject', 'BECA', 'issue', 'academic_program', 'P'];
        yield 'beca business' => ['subject', 'BECA', 'issue', 'business', 'A'];
        yield 'campania' => ['subject', 'CAMPANIA', 'issue', 'campaign', 'C'];
        yield 'beneficio contextual' => ['subject', 'BENEFICIO', 'issue', 'business', 'A'];
        yield 'categoria contextual' => ['subject', 'CATEGORIA', 'issue', 'business', 'A'];
        yield 'unknown scope' => ['subject', 'NEGOCIO', 'issue', 'association', 'A'];
        yield 'incomplete' => ['subject', 'NEGOCIO', 'issue', 'business', null];
        yield 'incomplete reverse' => ['subject', 'NEGOCIO', 'issue', null, 'A'];
        yield 'empty context' => ['subject', 'NEGOCIO', 'issue', 'business', ''];
        yield 'bad context' => ['subject', 'NEGOCIO', 'issue', 'business', 'bad id'];
        yield 'missing subject' => [null, 'NEGOCIO', 'issue', null, null];
        yield 'unknown type' => ['subject', 'UNKNOWN', 'issue', null, null];
        yield 'other operation' => ['subject', 'NEGOCIO', 'transfer', null, null];
    }

    #[DataProvider('blockedInputs')]
    public function test_blocked_inputs_never_reach_validator_or_motor(?string $subject, string $type, string $operation, ?string $scope, ?string $id): void
    {
        $calls = [];
        $policy = new FinancialAuthorizationPolicy($this->engine(fn () => true, $calls), $this->validator(fn () => throw new LogicException('Must not validate blocked context')));
        self::assertFalse($policy->allows($subject, $type, $operation, $scope, $id));
        self::assertSame([], $calls);
    }

    public static function failureSources(): iterable { yield 'motor' => [false]; yield 'context' => [true]; }

    #[DataProvider('failureSources')]
    public function test_known_database_errors_are_sanitized_with_original_cause(bool $context): void
    {
        $calls = []; $cause = new ConnectionException('Synthetic sensitive driver detail');
        $policy = new FinancialAuthorizationPolicy($this->engine(fn () => throw $cause, $calls),
            $this->validator(fn () => $context ? throw $cause : FinancialContextValidation::Valid));
        try { $policy->allows('subject', 'NEGOCIO', 'issue', 'business', 'A'); self::fail('Expected unavailable'); }
        catch (FinancialAuthorizationUnavailable $e) { self::assertSame($cause, $e->getPrevious()); self::assertSame('Authorization infrastructure unavailable.', $e->getMessage()); }
    }

    public function test_unexpected_errors_are_not_converted_to_ordinary_denials(): void
    {
        $calls = [];
        $policy = new FinancialAuthorizationPolicy($this->engine(fn () => throw new LogicException('Unexpected failure'), $calls));
        $this->expectException(LogicException::class);
        $policy->allows('admin', 'BENEFICIO', 'issue');
    }

    public function test_admin_identifier_has_no_special_policy_bypass(): void
    {
        $calls = [];
        self::assertFalse((new FinancialAuthorizationPolicy($this->engine(fn () => false, $calls)))->allows('admin', 'BENEFICIO', 'issue'));
        self::assertCount(1, $calls);
    }

    public function test_unexpected_validator_failure_is_not_hidden_as_denial(): void
    {
        $calls = [];
        $policy = new FinancialAuthorizationPolicy($this->engine(fn () => true, $calls),
            $this->validator(fn () => throw new LogicException('Unexpected validator failure')));
        try { $policy->allows('subject', 'NEGOCIO', 'issue', 'business', 'A'); self::fail('Expected validator failure'); }
        catch (LogicException $e) { self::assertSame('Unexpected validator failure', $e->getMessage()); }
        self::assertSame([], $calls);
    }

    public function test_explicit_dependency_exception_from_validator_is_preserved(): void
    {
        $calls = []; $failure = new FinancialAuthorizationUnavailable;
        $policy = new FinancialAuthorizationPolicy($this->engine(fn () => true, $calls), $this->validator(fn () => throw $failure));
        try { $policy->allows('subject', 'NEGOCIO', 'issue', 'business', 'A'); self::fail('Expected unavailable'); }
        catch (FinancialAuthorizationUnavailable $e) { self::assertSame($failure, $e); }
        self::assertSame([], $calls);
    }

    public function test_laravel_container_resolves_policy_without_a_context_provider_or_application_bootstrap(): void
    {
        $container = new Container;
        // Laravel Application normally supplies this resolver; a bare Container does not.
        $container->resolveEnvironmentUsing(fn ($environments = null) => $environments === null ? 'testing'
            : in_array('testing', (array) $environments, true));
        $decision = $container->make(FinancialAuthorizationDecision::class);
        self::assertInstanceOf(FinancialAuthorizationPolicy::class, $decision);
        self::assertInstanceOf(FinancialAuthorizationService::class, $container->make(BonusAuthorization::class));
        self::assertFalse($container->bound(FinancialContextValidator::class));
        $this->expectException(FinancialAuthorizationUnavailable::class);
        $decision->allows('subject', 'NEGOCIO', 'issue', 'business', 'A');
    }
}
