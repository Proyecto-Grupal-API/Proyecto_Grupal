<?php

namespace Tests\Support;

use App\Contracts\FinancialGrantAuthority;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\BusinessAuthorizationService;
use App\Services\FinancialRoleGrantService;
use Database\Seeders\AuthorizationFoundationSeeder;

/** Technical mappings with a synthetic institutional designation, only in the isolated test DB. */
final class FinancialPolicyFixtures
{
    public static function seed($test): void
    {
        SyntheticDomainFixtures::guard();
        (require database_path('migrations/2026_10_05_000100_create_authorization_foundation_indexes.php'))->up();
        $test->seed(AuthorizationFoundationSeeder::class);
        foreach (Role::FINANCIAL_ROLES as $role) {
            Role::create(['name' => $role, 'display_name' => 'Financial testing definition']);
        }
        foreach (Permission::TECHNICAL_CATALOG as $key => [$label, $domain]) {
            if ($domain === 'financial') {
                Permission::create(['key' => $key, 'display_name' => $label, 'domain' => $domain, 'active' => true]);
            }
        }
        foreach (RolePermission::FINANCIAL_MAPPINGS as $role => $keys) {
            foreach ($keys as $key) {
                RolePermission::create(['role_key' => $role, 'permission_key' => $key]);
            }
        }
    }

    public static function writer(User $designated): FinancialRoleGrantService
    {
        SyntheticDomainFixtures::guard();
        $policy = new class((string) $designated->getKey()) implements FinancialGrantAuthority
        {
            public function __construct(private string $designatedId) {}

            public function allows(User $actor, User $subject, string $operation, string $role, ?string $scopeType, ?string $scopeId): bool
            {
                SyntheticDomainFixtures::guard();

                return (string) $actor->getKey() === $this->designatedId;
            }

            public function reference(): string
            {
                return 'synthetic-testing-designation-not-institutional';
            }
        };

        return new FinancialRoleGrantService($policy, app(BusinessAuthorizationService::class));
    }
}
