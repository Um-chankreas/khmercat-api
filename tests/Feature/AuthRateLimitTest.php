<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function attempt(string $email)
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'nope']);
    }

    public function test_limit_is_per_email_so_others_on_the_same_network_can_still_sign_in(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertNotSame(429, $this->attempt('typo@example.com')->status());
        }

        $blocked = $this->attempt('typo@example.com')->assertStatus(429);
        $this->assertNotNull($blocked->headers->get('Retry-After'));

        // Same IP, different person: not blocked.
        $this->assertNotSame(429, $this->attempt('someone.else@example.com')->status());
    }
}
