<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use App\Models\VideoReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class RestaurantVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $manager;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['username' => 'owner']);
        $this->manager = User::factory()->create(['username' => 'manager']);
        $this->restaurant = Restaurant::create(['name' => 'Angkor Bites', 'status' => 'active']);
        $this->restaurant->users()->attach($this->owner->id, ['role' => 'owner', 'status' => 'accepted']);
        $this->restaurant->users()->attach($this->manager->id, ['role' => 'manager', 'status' => 'accepted']);
        VideoReview::create([
            'user_id' => $this->owner->id,
            'restaurant_id' => $this->restaurant->id,
            'type' => VideoReview::TYPE_RESTAURANT_POST,
            'caption' => 'Angkor special',
            'status' => 'ready',
            'video_url' => 'http://x/v.mp4',
        ]);
    }

    /**
     * Laravel's test client remembers the last signed-in user between
     * requests in one test; forget it so each request is exactly who we say.
     */
    private function as(User $u): array
    {
        $this->resetAuth();

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($u)];
    }

    private function guest(): array
    {
        $this->resetAuth();

        return [];
    }

    /**
     * The JWT package keeps the first request (and its Authorization header)
     * in singletons, and the auth manager caches the resolved user. In a
     * real server every request starts fresh; here, rebuild them so each
     * request is authenticated as exactly who it says.
     */
    private function resetAuth(): void
    {
        foreach (['tymon.jwt', 'tymon.jwt.auth', 'tymon.jwt.parser'] as $service) {
            $this->app->forgetInstance($service);
        }
        JWTAuth::clearResolvedInstances();
        $this->app['auth']->forgetGuards();
    }

    public function test_unpublished_restaurant_is_hidden_from_public_but_not_its_team(): void
    {
        $id = $this->restaurant->id;
        $this->putJson("/api/restaurants/{$id}", ['is_published' => false], $this->as($this->manager))
            ->assertOk()->assertJsonPath('data.is_published', false);

        // Public: gone everywhere.
        $this->getJson("/api/restaurants/{$id}", $this->guest())->assertNotFound();
        $this->getJson("/api/restaurants/{$id}/menu", $this->guest())->assertNotFound();
        $this->get("/menu/{$id}")->assertNotFound();
        $this->getJson('/api/search?q=Angkor', $this->guest())
            ->assertJsonPath('data.counts.restaurants', 0)
            ->assertJsonPath('data.counts.videos', 0);
        $this->assertCount(0, $this->getJson('/api/videos/feed', $this->guest())->json('data.contents'));

        // Team: still works.
        $this->getJson("/api/restaurants/{$id}", $this->as($this->owner))->assertOk();

        // Publish again → back.
        $this->putJson("/api/restaurants/{$id}", ['is_published' => true], $this->as($this->owner))->assertOk();
        $this->getJson("/api/restaurants/{$id}", $this->guest())->assertOk();
        $this->assertCount(1, $this->getJson('/api/videos/feed', $this->guest())->json('data.contents'));
    }

    public function test_only_the_owner_can_delete_with_the_right_password(): void
    {
        $url = "/api/restaurants/{$this->restaurant->id}";

        $this->deleteJson($url, ['password' => 'password'], $this->as($this->manager))->assertForbidden();
        $this->deleteJson($url, ['password' => 'wrong'], $this->as($this->owner))
            ->assertStatus(422)->assertJsonPath('errors.password.0', 'Incorrect password.');
        $this->assertNotSoftDeleted($this->restaurant);
    }

    public function test_deleting_resets_active_profiles_and_hides_everything(): void
    {
        $this->owner->update(['active_restaurant_id' => $this->restaurant->id]);
        $this->manager->update(['active_restaurant_id' => $this->restaurant->id]);

        $this->deleteJson("/api/restaurants/{$this->restaurant->id}", ['password' => 'password'], $this->as($this->owner))
            ->assertOk();

        $this->assertSoftDeleted($this->restaurant);
        $this->assertNull($this->owner->fresh()->active_restaurant_id);
        $this->assertNull($this->manager->fresh()->active_restaurant_id);
        $this->getJson("/api/restaurants/{$this->restaurant->id}", $this->guest())->assertNotFound();
        $this->assertCount(0, $this->getJson('/api/videos/feed', $this->guest())->json('data.contents'));
        $this->getJson('/api/restaurants/mine', $this->as($this->owner))->assertJsonCount(0, 'data.restaurants');
    }
}
