<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use App\Models\VideoReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class RestaurantVideoTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $stranger;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['username' => 'owner']);
        $this->stranger = User::factory()->create(['username' => 'stranger']);
        $this->restaurant = Restaurant::create(['name' => 'Angkor Bites', 'status' => 'active']);
        $this->restaurant->users()->attach($this->owner->id, ['role' => 'owner', 'status' => 'accepted']);
    }

    private function as(User $user): array
    {
        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }

    private function video(string $type = VideoReview::TYPE_RESTAURANT_POST, ?User $by = null): VideoReview
    {
        return VideoReview::create([
            'user_id' => ($by ?? $this->owner)->id,
            'restaurant_id' => $this->restaurant->id,
            'type' => $type,
            'status' => 'ready',
            'video_url' => 'reviews/videos/x.mp4',
        ]);
    }

    public function test_team_deletes_a_post_and_sees_it_in_the_deleted_list(): void
    {
        $kept = $this->video();
        $gone = $this->video();
        $base = "/api/restaurants/{$this->restaurant->id}/videos";

        $this->deleteJson("{$base}/{$gone->id}", [], $this->as($this->owner))->assertOk();

        $this->assertSoftDeleted('video_reviews', ['id' => $gone->id]);
        $this->assertNotSoftDeleted('video_reviews', ['id' => $kept->id]);
        $this->getJson("{$base}/deleted", $this->as($this->owner))
            ->assertOk()
            ->assertJsonCount(1, 'data.contents')
            ->assertJsonPath('data.contents.0.id', $gone->id)
            ->assertJsonPath('data.meta.has_more', false);
    }

    public function test_outsiders_cannot_delete_or_list(): void
    {
        $video = $this->video();
        $base = "/api/restaurants/{$this->restaurant->id}/videos";

        $this->deleteJson("{$base}/{$video->id}", [], $this->as($this->stranger))->assertForbidden();
        $this->getJson("{$base}/deleted", $this->as($this->stranger))->assertForbidden();
        $this->assertNotSoftDeleted('video_reviews', ['id' => $video->id]);
    }

    public function test_customer_reviews_are_not_the_restaurants_to_delete(): void
    {
        $review = $this->video(VideoReview::TYPE_REVIEW, $this->stranger);

        $this->deleteJson(
            "/api/restaurants/{$this->restaurant->id}/videos/{$review->id}",
            [],
            $this->as($this->owner),
        )->assertNotFound();
        $this->assertNotSoftDeleted('video_reviews', ['id' => $review->id]);
    }
}
