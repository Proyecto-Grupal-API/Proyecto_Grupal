<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithPointsCollections;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use InteractsWithPointsCollections;

    protected function setUp(): void
    {
        parent::setUp();
        User::query()->delete();
    }

    public function test_login_returns_token_and_user_profile(): void
    {
        $user = User::create([
            'email'    => 'test.student@campus.edu',
            'name'     => 'Estudiante Test',
            'role'     => User::ROLE_STUDENT,
            'password' => Hash::make('password123'),
            'status'   => 'active',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email'    => 'test.student@campus.edu',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'token',
                'user' => ['id', 'email', 'name', 'role', 'permissions'],
                'home_area',
            ])
            ->assertJson([
                'success'   => true,
                'home_area' => 'student',
            ]);
    }

    public function test_login_rejects_invalid_password(): void
    {
        User::create([
            'email'    => 'test.student@campus.edu',
            'name'     => 'Estudiante Test',
            'role'     => User::ROLE_STUDENT,
            'password' => Hash::make('password123'),
            'status'   => 'active',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email'    => 'test.student@campus.edu',
            'password' => 'wrong_password',
        ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_me_returns_authenticated_user(): void
    {
        $user = User::create([
            'email'    => 'prof@campus.edu',
            'name'     => 'Profesor Test',
            'role'     => User::ROLE_PROFESSOR,
            'password' => Hash::make('password123'),
            'status'   => 'active',
        ]);

        $token = $user->createToken('test_token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'user'    => [
                    'email' => 'prof@campus.edu',
                    'role'  => 'professor',
                ],
                'home_area' => 'professor',
            ]);
    }

    public function test_logout_revokes_token(): void
    {
        $user = User::create([
            'email'    => 'admin@campus.edu',
            'name'     => 'Admin Test',
            'role'     => User::ROLE_ADMIN,
            'password' => Hash::make('password123'),
            'status'   => 'active',
        ]);

        $token = $user->createToken('test_token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // Intentar usar el token revocado debe fallar
        $this->app['auth']->forgetGuards();
        $check = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me');

        $check->assertStatus(401);
    }
}
