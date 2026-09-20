<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_user_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/user')->assertStatus(401);
    }

    public function test_get_user_returns_authenticated_user_info(): void
    {
        $user = $this->createUser();

        Passport::actingAs($user);
        $response = $this->getJson('/api/user');

        $response->assertStatus(200)
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', $user->email);
    }

    public function test_version_endpoint_is_public(): void
    {
        $response = $this->getJson('/api/version');

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'data' => ['version', 'name', 'laravel_version', 'PHP_VERSION'],
            ]);
    }
}
