<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiResponseModeTest extends TestCase
{
    public function test_protected_api_routes_return_json_unauthorized_without_json_accept_header(): void
    {
        $response = $this
            ->withHeaders(['Accept' => '*/*'])
            ->get('/api/dashboard');

        $response
            ->assertStatus(401)
            ->assertHeader('content-type', 'application/json')
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_api_validation_errors_return_json_without_json_accept_header(): void
    {
        $response = $this
            ->withHeaders(['Accept' => '*/*'])
            ->post('/api/auth/login', []);

        $response
            ->assertStatus(422)
            ->assertHeader('content-type', 'application/json')
            ->assertJsonValidationErrors(['email', 'password']);
    }
}
