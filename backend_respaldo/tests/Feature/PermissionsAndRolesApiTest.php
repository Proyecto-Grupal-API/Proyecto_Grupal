<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithPointsCollections;
use Tests\TestCase;

class PermissionsAndRolesApiTest extends TestCase
{
    use InteractsWithPointsCollections;

    protected function setUp(): void
    {
        parent::setUp();
        User::query()->delete();
        $this->cleanPointsCollections();
    }

    public function test_student_cannot_access_admin_ledger(): void
    {
        $student = User::create([
            'email'    => 'student@campus.edu',
            'name'     => 'Student',
            'role'     => User::ROLE_STUDENT,
            'password' => Hash::make('password123'),
            'status'   => 'active',
        ]);

        $token = $student->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/points/ledger');

        $response->assertStatus(403);
    }

    public function test_student_cannot_view_another_students_balance(): void
    {
        $studentA = User::create([
            'email'    => 'student.a@campus.edu',
            'name'     => 'Student A',
            'role'     => User::ROLE_STUDENT,
            'password' => Hash::make('password123'),
            'status'   => 'active',
        ]);

        $tokenA = $studentA->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->getJson('/api/v1/points/balance/other-student-id');

        $response->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    public function test_student_cannot_create_earning_rules(): void
    {
        $student = User::create([
            'email'    => 'student@campus.edu',
            'name'     => 'Student',
            'role'     => User::ROLE_STUDENT,
            'password' => Hash::make('password123'),
            'status'   => 'active',
        ]);

        $token = $student->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/rules', [
                'business_id' => 'biz-1',
                'name'        => 'Regla Fake',
            ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_access_admin_endpoints(): void
    {
        $admin = User::create([
            'email'    => 'admin@campus.edu',
            'name'     => 'Admin',
            'role'     => User::ROLE_ADMIN,
            'password' => Hash::make('password123'),
            'status'   => 'active',
        ]);

        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/points/ledger');

        $response->assertStatus(200);
    }
}
