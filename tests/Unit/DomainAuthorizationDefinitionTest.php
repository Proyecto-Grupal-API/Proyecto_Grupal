<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Services\BusinessAuthorizationService;
use App\Services\EffectiveRoleAssignments;
use App\Services\FinancialAuthorizationService;
use App\Services\RewardsAuthorizationService;

test('technical definitions preserve foundation catalogs and isolate financial policy from rewards', function () {
    expect(Permission::DOMAINS)->toBe(['business', 'institutional', 'financial', 'rewards', 'student_services'])
        ->and(Permission::CATALOG)->toHaveCount(8)
        ->and(Permission::TECHNICAL_CATALOG)->toHaveCount(22)
        ->and(Permission::definitions())->toHaveCount(30)
        // E1-01/E1-02: budget.* separated and three designation capabilities; definitions only, no new mappings.
        ->and(Permission::TECHNICAL_CATALOG)->toHaveKeys(['budget.approve', 'budget.adjust', 'budget.suspend', 'budget.resume',
            'identity.refunds.designate', 'identity.services.designate', 'identity.budgets.designate'])
        ->and(Role::VALID_ROLES)->toHaveCount(6)
        ->and(Role::FOUNDATION_ROLES)->toHaveCount(16)
        ->and(Role::BUSINESS_ROLES)->toBe(['business_owner', 'business_manager', 'cashier', 'inventory_manager', 'buyer'])
        ->and(array_keys(Role::FINANCIAL_ROLE_SCOPES))->toBe(Role::FINANCIAL_ROLES)
        ->and(Role::AUTHORIZATION_ROLES)->toHaveCount(35)->and(Role::REWARDS_ROLE_SCOPES)->toBe([])
        ->and(array_keys(RolePermission::FINANCIAL_MAPPINGS))->toBe(Role::FINANCIAL_ROLES)->and(RolePermission::REWARDS_MAPPINGS)->toBe([])
        ->and(array_sum(array_map('count', RolePermission::MAPPINGS)))->toBe(13);
});

test('domain capability definitions are finite and do not promise cross-module audit or campaigns', function () {
    foreach (FinancialAuthorizationService::BONUS_CAPABILITIES as $key) {
        expect(Permission::definitions()[$key][1])->toBe('financial');
    }
    foreach (RewardsAuthorizationService::CAPABILITY_SCOPES as $key => $scopes) {
        expect(Permission::definitions()[$key][1])->toBe('rewards')
            ->and($scopes)->not->toContain('campaign', 'platform', 'campus', 'academic_program');
    }
    expect(Permission::TECHNICAL_CATALOG)->not->toHaveKeys(['audit.write', 'analytics.write']);
});

test('invalid financial requests fail before any application or database is available', function () {
    $assignments = new EffectiveRoleAssignments;
    $engine = new FinancialAuthorizationService($assignments, new BusinessAuthorizationService($assignments));
    expect($engine->allows('unresolved-subject', 'UNKNOWN', 'issue'))->toBeFalse()
        ->and($engine->allows('unresolved-subject', 'BECA', 'transfer'))->toBeFalse()
        ->and($engine->allows('unresolved-subject', 'CAMPANIA', 'issue', 'campaign', 'missing'))->toBeFalse();
});

test('unknown rewards capabilities and domains fail before any application or database is available', function () {
    $assignments = new EffectiveRoleAssignments;
    $engine = new RewardsAuthorizationService($assignments, new BusinessAuthorizationService($assignments));
    expect($engine->allows('unresolved-subject', 'business.manage', 'business', 'A'))->toBeFalse()
        ->and($engine->allows('unresolved-subject', 'audit.write'))->toBeFalse()
        ->and($engine->allows('unresolved-subject', 'analytics.view', 'campaign', 'A'))->toBeFalse();
});
