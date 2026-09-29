<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class ProfileSwitchTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Restaurant $mine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['username' => 'owner']);
        $this->mine = Restaurant::create(['name' => 'Angkor Bites', 'status' => 'active']);
        $this->mine->users()->attach($this->owner->id, ['role' => 'owner', 'status' => 'accepted']);
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($this->owner)];
    }

    public function test_switch_to_restaurant_then_back_to_personal(): void
    {
        $this->postJson('/api/restaurants/switch', ['restaurant_id' => $this->mine->id], $this->auth())
            ->assertOk()->assertJsonPath('data.active_restaurant_id', $this->mine->id);

        $this->getJson('/api/restaurants/mine', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.active_restaurant_id', $this->mine->id)
            ->assertJsonPath('data.restaurants.0.followers_count', 0)
            ->assertJsonPath('data.restaurants.0.reviews_count', 0);

        $this->postJson('/api/restaurants/switch', ['restaurant_id' => null, 'password' => 'password'], $this->auth())
            ->assertOk()->assertJsonPath('data.active_restaurant_id', null);
        $this->assertNull($this->owner->fresh()->active_restaurant_id);
    }

    public function test_switching_to_personal_requires_the_correct_password(): void
    {
        $this->owner->update(['active_restaurant_id' => $this->mine->id]);

        $this->postJson('/api/restaurants/switch', ['restaurant_id' => null], $this->auth())
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson('/api/restaurants/switch', ['restaurant_id' => null, 'password' => 'wrong'], $this->auth())
            ->assertStatus(422)->assertJsonPath('errors.password.0', 'Incorrect password.');

        $this->assertSame($this->mine->id, $this->owner->fresh()->active_restaurant_id);
    }

    public function test_cannot_switch_to_someone_elses_restaurant(): void
    {
        $other = Restaurant::create(['name' => 'Not mine', 'status' => 'active']);

        $this->postJson('/api/restaurants/switch', ['restaurant_id' => $other->id], $this->auth())
            ->assertForbidden();
    }

    public function test_stale_active_restaurant_is_reported_as_personal(): void
    {
        $other = Restaurant::create(['name' => 'Left it', 'status' => 'active']);
        $this->owner->update(['active_restaurant_id' => $other->id]);

        $this->getJson('/api/restaurants/mine', $this->auth())
            ->assertOk()->assertJsonPath('data.active_restaurant_id', null);
    }
}
