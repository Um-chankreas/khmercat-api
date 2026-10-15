<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use App\Models\VideoReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class VideoSaveTest extends TestCase
{
    use RefreshDatabase;

    private User $viewer;

    private User $other;

    private VideoReview $video;

    protected function setUp(): void
    {
        parent::setUp();
        $this->viewer = User::factory()->create(['username' => 'viewer']);
        $this->other = User::factory()->create(['username' => 'other']);
        $restaurant = Restaurant::create(['name' => 'Angkor Bites', 'status' => 'active']);
        $this->video = VideoReview::create([
            'user_id' => $this->other->id,
            'restaurant_id' => $restaurant->id,
            'type' => VideoReview::TYPE_RESTAURANT_POST,
            'status' => 'ready',
            'video_url' => 'reviews/videos/x.mp4',
        ]);
    }

    private function as(User $user): array
    {
        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }

    public function test_saving_and_unsaving_return_the_new_count(): void
    {
        $url = "/api/videos/{$this->video->id}/save";

        $this->postJson($url, [], $this->as($this->viewer))
            ->assertOk()
            ->assertJsonPath('data.saves_count', 1);

        // Saving again is a no-op, not a second save.
        $this->postJson($url, [], $this->as($this->viewer))
            ->assertOk()
            ->assertJsonPath('data.saves_count', 1);

        $this->postJson($url, [], $this->as($this->other))
            ->assertOk()
            ->assertJsonPath('data.saves_count', 2);

        $this->deleteJson($url, [], $this->as($this->viewer))
            ->assertOk()
            ->assertJsonPath('data.saves_count', 1);
    }

    public function test_feed_reports_the_count_and_whether_the_viewer_saved_it(): void
    {
        $this->video->saves()->create(['user_id' => $this->other->id]);

        $this->getJson('/api/videos/feed', $this->as($this->viewer))
            ->assertOk()
            ->assertJsonPath('data.contents.0.saves_count', 1)
            ->assertJsonPath('data.contents.0.saved_by_me', false);

        $this->getJson('/api/videos/feed', $this->as($this->other))
            ->assertOk()
            ->assertJsonPath('data.contents.0.saves_count', 1)
            ->assertJsonPath('data.contents.0.saved_by_me', true);
    }

    public function test_guests_cannot_save(): void
    {
        $this->postJson("/api/videos/{$this->video->id}/save")->assertUnauthorized();
    }
}
