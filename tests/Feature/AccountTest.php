<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use App\Models\VideoComment;
use App\Models\VideoReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $viewer;

    private Restaurant $restaurant;

    private VideoReview $video;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['username' => 'sokha', 'email' => 'sokha@example.com']);
        $this->viewer = User::factory()->create(['username' => 'viewer']);
        $this->restaurant = Restaurant::create(['name' => 'Angkor Bites', 'status' => 'active']);
        $this->video = VideoReview::create([
            'user_id' => $this->user->id,
            'restaurant_id' => $this->restaurant->id,
            'type' => VideoReview::TYPE_REVIEW,
            'caption' => 'Great amok',
            'status' => 'ready',
        ]);
        VideoComment::create([
            'video_review_id' => $this->video->id,
            'user_id' => $this->user->id,
            'body' => 'So good',
        ]);
    }

    private function as(User $user): array
    {
        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }

    public function test_wrong_password_is_rejected_inline(): void
    {
        $this->postJson('/api/account/deactivate', ['password' => 'nope'], $this->as($this->user))
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'Incorrect password.');
        $this->deleteJson('/api/account', ['password' => 'nope'], $this->as($this->user))
            ->assertStatus(422);

        $this->assertNull($this->user->fresh()->deactivated_at);
    }

    public function test_deactivating_hides_profile_videos_and_comments(): void
    {
        $this->postJson('/api/account/deactivate', ['password' => 'password'], $this->as($this->user))
            ->assertOk();

        $this->assertNotNull($this->user->fresh()->deactivated_at);
        $this->getJson('/api/users/sokha')->assertNotFound();
        $this->getJson("/api/videos/{$this->video->id}")->assertNotFound();
        $this->assertCount(0, $this->getJson('/api/videos/feed')->json('data.contents') ?? []);
        $this->assertCount(0, $this->getJson("/api/videos/{$this->video->id}/comments")->json('data.contents'));
    }

    public function test_deactivated_account_is_signed_out_on_other_devices(): void
    {
        $otherDevice = $this->as($this->user);
        $this->postJson('/api/account/deactivate', ['password' => 'password'], $this->as($this->user))
            ->assertOk();

        $this->getJson('/api/auth/get-user-account', $otherDevice)->assertUnauthorized();
    }

    public function test_logging_in_again_reactivates(): void
    {
        $this->user->forceFill(['deactivated_at' => now()])->save();

        $this->postJson('/api/auth/login', ['email' => 'sokha@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.reactivated', true);

        $this->assertNull($this->user->fresh()->deactivated_at);
        $this->getJson('/api/users/sokha')->assertOk();
    }

    public function test_deleting_removes_the_account_and_frees_the_email(): void
    {
        $owned = Restaurant::create(['name' => 'My Cafe', 'status' => 'active']);
        $owned->users()->attach($this->user->id, ['role' => 'owner', 'status' => 'accepted']);
        $this->viewer->follow($this->user);

        $this->deleteJson('/api/account', ['password' => 'password'], $this->as($this->user))
            ->assertOk();

        $this->assertSoftDeleted('users', ['id' => $this->user->id]);
        $this->assertSoftDeleted('restaurants', ['id' => $owned->id]);
        $this->assertSoftDeleted('video_reviews', ['id' => $this->video->id]);
        $this->assertDatabaseMissing('video_comments', ['user_id' => $this->user->id]);
        $this->assertDatabaseMissing('follows', ['followable_id' => $this->user->id]);
        $this->getJson('/api/users/sokha')->assertNotFound();

        $this->postJson('/api/auth/login', ['email' => 'sokha@example.com', 'password' => 'password'])
            ->assertUnauthorized();
        $this->postJson('/api/auth/register', [
            'name' => 'Sokha',
            'email' => 'sokha@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();
    }
}
