<?php

namespace Tests\Support;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\BusinessAuthorizationService;
use App\Services\EffectiveRoleAssignments;
use App\Services\FinancialAuthorizationService;
use App\Services\RewardsAuthorizationService;
use Database\Seeders\AuthorizationFoundationSeeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** Synthetic policies only: no institutional authority is asserted or registered in production. */
final class SyntheticDomainFixtures
{
    public const FINANCIAL_SCOPES = ['fixture_financial_global' => null,
        'fixture_financial_business' => 'business', 'fixture_financial_program' => 'academic_program'];

    public const REWARDS_SCOPES = ['fixture_rewards_global' => null, 'fixture_rewards_business' => 'business'];

    public static function guard(): void
    {
        E1TestTarget::assertApplication();
        if (! app()->environment('testing') || config('database.default') !== 'mongodb') {
            throw new RuntimeException('Synthetic policies are restricted to MongoDB testing.');
        }
        TestCase::assertSafeMongoDatabaseName(DB::connection('mongodb')->getDatabaseName());
    }

    public static function financialMatrix(): array
    {
        return ['fixture_financial_global' => array_values(FinancialAuthorizationService::BONUS_CAPABILITIES),
            'fixture_financial_business' => ['bonuses.negocio.issue'],
            'fixture_financial_program' => ['bonuses.beca.issue']];
    }

    public static function rewardsMatrix(): array
    {
        return ['fixture_rewards_global' => array_keys(RewardsAuthorizationService::CAPABILITY_SCOPES),
            'fixture_rewards_business' => ['rewards.catalog.manage', 'rewards.redemption.deliver', 'analytics.view']];
    }

    public static function seed($test): void
    {
        self::guard();
        (require database_path('migrations/2026_10_05_000100_create_authorization_foundation_indexes.php'))->up();
        $test->seed(AuthorizationFoundationSeeder::class);
        foreach (Permission::TECHNICAL_CATALOG as $key => [$label, $domain]) {
            Permission::create(['key' => $key, 'display_name' => $label, 'domain' => $domain, 'active' => true]);
        }
        foreach ([...self::financialMatrix(), ...self::rewardsMatrix()] as $role => $permissions) {
            Role::create(['name' => $role, 'display_name' => 'Synthetic testing policy']);
            foreach ($permissions as $permission) {
                // Deliberate isolated storage fixtures; production RolePermission rejects these mappings.
                DB::connection('mongodb')->getCollection('role_permissions')->insertOne([
                    'role_key' => $role, 'permission_key' => $permission,
                ]);
            }
        }
    }

    public static function assignment(User $user, string $role, ?string $type = null, ?string $id = null, array $changes = []): void
    {
        self::guard();
        // These synthetic roles are intentionally absent from Role::FOUNDATION_ROLES.
        DB::connection('mongodb')->getCollection('role_assignments')->insertOne([...[
            'user_id' => (string) $user->getKey(), 'role_key' => $role, 'scope_type' => $type, 'scope_id' => $id,
            'status' => 'active', 'is_current' => true, 'generation' => 1, 'revision' => 1,
            'starts_at' => null, 'ends_at' => null,
        ], ...$changes]);
    }

    public static function financial(): FinancialAuthorizationService
    {
        self::guard();

        return new class(app(EffectiveRoleAssignments::class), app(BusinessAuthorizationService::class)) extends FinancialAuthorizationService
        {
            protected function roleScopes(): array
            {
                return SyntheticDomainFixtures::FINANCIAL_SCOPES;
            }

            protected function mappings(): array
            {
                return SyntheticDomainFixtures::financialMatrix();
            }
        };
    }

    public static function rewards(): RewardsAuthorizationService
    {
        self::guard();

        return new class(app(EffectiveRoleAssignments::class), app(BusinessAuthorizationService::class)) extends RewardsAuthorizationService
        {
            protected function roleScopes(): array
            {
                return SyntheticDomainFixtures::REWARDS_SCOPES;
            }

            protected function mappings(): array
            {
                return SyntheticDomainFixtures::rewardsMatrix();
            }
        };
    }
}
