<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class UserProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_includes_social_links_verified_and_follow_state(): void
    {
        $dara = User::factory()->create([
            'username' => 'dara',
            'is_verified' => true,
            'facebook_url' => 'https://facebook.com/dara',
            'tiktok_url' => '@dara.eats',
            'telegram_username' => '@dara',
        ]);
        $viewer = User::factory()->create(['username' => 'viewer']);
        $viewer->follow($dara);

        $this->getJson('/api/users/dara')
            ->assertOk()
            ->assertJsonPath('data.facebook_url', 'https://facebook.com/dara')
            ->assertJsonPath('data.tiktok_url', '@dara.eats')
            ->assertJsonPath('data.telegram_username', '@dara')
            ->assertJsonPath('data.is_verified', true)
            ->assertJsonPath('data.reviews_count', 0)
            ->assertJsonPath('data.is_following', false); // guest

        $this->getJson('/api/users/dara', ['Authorization' => 'Bearer '.JWTAuth::fromUser($viewer)])
            ->assertOk()
            ->assertJsonPath('data.is_following', true)
            ->assertJsonMissingPath('data.email');
    }
}
