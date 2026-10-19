<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantReview;
use App\Models\User;
use App\Models\VideoReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class RestaurantReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $alice;

    private User $bob;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['username' => 'owner']);
        $this->alice = User::factory()->create(['username' => 'alice']);
        $this->bob = User::factory()->create(['username' => 'bob']);
        $this->restaurant = Restaurant::create(['name' => 'Angkor Bites', 'status' => Restaurant::STATUS_APPROVED, 'is_published' => true]);
        $this->restaurant->users()->attach($this->owner->id, ['role' => 'owner', 'status' => 'accepted']);
    }

    private function as(User $user): array
    {
        $this->freshAuth();

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }

    /**
     * The JWT guard and parser hold on to the first request's user and
     * token for the whole test; drop them so each request signs in anew.
     */
    private function freshAuth(): void
    {
        foreach (['tymon.jwt', 'tymon.jwt.auth', 'tymon.jwt.parser'] as $id) {
            $this->app->forgetInstance($id);
        }
        JWTAuth::clearResolvedInstances();
        auth()->forgetGuards();
    }

    private function url(string $suffix = ''): string
    {
        return "/api/restaurants/{$this->restaurant->id}/reviews{$suffix}";
    }

    public function test_posting_again_updates_the_one_review(): void
    {
        $this->postJson($this->url(), ['rating' => 3, 'comment' => 'Okay'], $this->as($this->alice))
            ->assertOk()
            ->assertJsonPath('data.rating', 3);
        $this->postJson($this->url(), ['rating' => 5, 'comment' => 'Great now'], $this->as($this->alice))
            ->assertOk()
            ->assertJsonPath('data.comment', 'Great now');

        $this->assertSame(1, RestaurantReview::count());
        $this->assertSame(5, RestaurantReview::first()->rating);
    }

    public function test_list_has_summary_filter_and_my_review(): void
    {
        $this->postJson($this->url(), ['rating' => 4, 'comment' => 'Good'], $this->as($this->alice))->assertOk();
        $video = VideoReview::create([
            'user_id' => $this->bob->id,
            'restaurant_id' => $this->restaurant->id,
            'type' => VideoReview::TYPE_REVIEW,
            'status' => 'ready',
            'rating' => 5,
            'video_url' => 'reviews/videos/x.mp4',
        ]);
        RestaurantReview::upsertFor($this->restaurant->id, $this->bob->id, 5, 'Juicy', $video->id);

        $this->getJson($this->url(), $this->as($this->alice))
            ->assertOk()
            ->assertJsonPath('data.summary.total', 2)
            ->assertJsonPath('data.summary.average', 4.5)
            ->assertJsonPath('data.summary.counts.5', 1)
            ->assertJsonPath('data.summary.counts.4', 1)
            ->assertJsonPath('data.with_video_count', 1)
            ->assertJsonPath('data.my_review.comment', 'Good')
            ->assertJsonCount(2, 'data.contents');

        $this->freshAuth();
        $this->getJson($this->url('?filter=video'))
            ->assertOk()
            ->assertJsonCount(1, 'data.contents')
            ->assertJsonPath('data.contents.0.video.id', $video->id)
            ->assertJsonPath('data.my_review', null);

        $this->getJson("/api/restaurants/{$this->restaurant->id}")
            ->assertJsonPath('data.reviews_count', 2)
            ->assertJsonPath('data.avg_rating', 4.5);
    }

    public function test_uploaded_review_video_becomes_the_review(): void
    {
        Queue::fake();
        $this->postJson($this->url(), ['rating' => 2, 'comment' => 'Meh'], $this->as($this->alice))->assertOk();

        $this->post('/api/reviews/upload', [
            'video' => UploadedFile::fake()->create('clip.mp4', 100, 'video/mp4'),
            'restaurant_id' => $this->restaurant->id,
            'rating' => 5,
            'caption' => 'Much better #burger',
        ], $this->as($this->alice))->assertOk();

        $review = RestaurantReview::sole();
        $this->assertSame(5, $review->rating);
        $this->assertSame('Much better', $review->comment);
        $this->assertNotNull($review->video_review_id);
    }

    public function test_likes_and_owner_reply(): void
    {
        $id = $this->postJson($this->url(), ['rating' => 4, 'comment' => 'Nice'], $this->as($this->alice))
            ->json('data.id');

        $this->postJson("/api/reviews/{$id}/like", [], $this->as($this->bob))
            ->assertOk()->assertJsonPath('data.likes_count', 1);
        $this->postJson("/api/reviews/{$id}/like", [], $this->as($this->bob))
            ->assertOk()->assertJsonPath('data.likes_count', 1);

        $this->putJson("/api/reviews/{$id}/reply", ['text' => 'Thanks!'], $this->as($this->bob))->assertForbidden();
        $this->putJson("/api/reviews/{$id}/reply", ['text' => 'Thanks!'], $this->as($this->owner))
            ->assertOk()
            ->assertJsonPath('data.reply.text', 'Thanks!');

        $this->getJson($this->url(), $this->as($this->bob))
            ->assertJsonPath('data.contents.0.liked_by_me', true)
            ->assertJsonPath('data.contents.0.reply.text', 'Thanks!');
    }

    public function test_team_cannot_review_its_own_restaurant(): void
    {
        $this->postJson($this->url(), ['rating' => 5, 'comment' => 'Best'], $this->as($this->owner))->assertForbidden();
        $this->postJson($this->url(), ['rating' => 5], $this->as($this->alice))->assertUnprocessable();
    }
}
