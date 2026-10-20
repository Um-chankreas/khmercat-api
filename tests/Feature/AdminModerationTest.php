<?php

namespace Tests\Feature;

use App\Models\AdminAction;
use App\Models\ContentReport;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\VideoReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class AdminModerationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $sokha;

    private User $reporter;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['username' => 'admin']);
        $this->admin->forceFill(['is_admin' => true])->save();
        $this->sokha = User::factory()->create(['username' => 'sokha_eats', 'password' => 'secret123']);
        $this->reporter = User::factory()->create(['username' => 'reporter']);
        $this->restaurant = Restaurant::create([
            'name' => 'Angkor Bites',
            'status' => Restaurant::STATUS_APPROVED,
            'is_published' => true,
        ]);
    }

    /** The JWT guard and parser keep the first request's user and token. */
    private function freshAuth(): void
    {
        foreach (['tymon.jwt', 'tymon.jwt.auth', 'tymon.jwt.parser'] as $id) {
            $this->app->forgetInstance($id);
        }
        JWTAuth::clearResolvedInstances();
        auth()->forgetGuards();
    }

    private function as(User $user): array
    {
        $this->freshAuth();

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }

    private function reportedVideo(): VideoReview
    {
        $video = VideoReview::create([
            'user_id' => $this->sokha->id,
            'restaurant_id' => $this->restaurant->id,
            'type' => VideoReview::TYPE_REVIEW,
            'status' => 'ready',
            'video_url' => 'reviews/videos/x.mp4',
        ]);
        foreach (['sexual', 'sexual', 'other'] as $reason) {
            ContentReport::create([
                'reporter_id' => $this->reporter->id,
                'video_review_id' => $video->id,
                'reason' => $reason,
                'status' => ContentReport::STATUS_OPEN,
            ]);
        }

        return $video;
    }

    public function test_non_admins_are_kept_out(): void
    {
        $this->getJson('/api/admin/summary', $this->as($this->sokha))->assertForbidden();
    }

    public function test_summary_and_reports_with_reason_counts(): void
    {
        $this->reportedVideo();

        $this->getJson('/api/admin/summary', $this->as($this->admin))
            ->assertOk()
            ->assertJsonPath('data.open_reports', 1)
            ->assertJsonPath('data.active_restaurants', 1);
        $this->getJson('/api/admin/reports', $this->as($this->admin))
            ->assertOk()
            ->assertJsonPath('data.items.0.reports_count', 3)
            ->assertJsonPath('data.items.0.reason_counts.sexual', 2);
        $this->getJson('/api/admin/users?filter=reported', $this->as($this->admin))
            ->assertOk()
            ->assertJsonPath('data.users.0.username', 'sokha_eats')
            ->assertJsonPath('data.users.0.open_reports_count', 3);
    }

    public function test_ban_deletes_reported_videos_blocks_login_and_unban_restores(): void
    {
        $video = $this->reportedVideo();

        $this->postJson("/api/admin/users/{$this->sokha->id}/ban", [
            'days' => 7,
            'reason' => 'sexual',
            'delete_reported_videos' => true,
        ], $this->as($this->admin))
            ->assertOk()
            ->assertJsonPath('data.deleted_videos', 1);

        $this->assertSoftDeleted('video_reviews', ['id' => $video->id]);
        $this->assertTrue($this->sokha->fresh()->isBanned());
        $this->getJson('/api/admin/reports?status=resolved', $this->as($this->admin))
            ->assertJsonPath('data.items.0.resolution.status', 'removed')
            ->assertJsonPath('data.items.0.resolution.resolved_by.username', 'admin');

        // Signed out everywhere.
        $this->getJson('/api/auth/get-user-account', $this->as($this->sokha))->assertUnauthorized();

        $this->postJson("/api/admin/users/{$this->sokha->id}/unban", [], $this->as($this->admin))->assertOk();
        $this->assertFalse($this->sokha->fresh()->isBanned());
        $this->assertSame(['ban', 'unban'], AdminAction::orderBy('id')->pluck('action')->all());
    }

    public function test_admins_cannot_be_banned(): void
    {
        $this->postJson("/api/admin/users/{$this->admin->id}/ban", ['reason' => 'spam'], $this->as($this->admin))
            ->assertUnprocessable();
    }

    public function test_deactivated_restaurant_is_hidden_until_activated(): void
    {
        $id = $this->restaurant->id;
        $this->postJson("/api/admin/restaurants/{$id}/deactivate", ['reason' => 'Fake photos'], $this->as($this->admin))
            ->assertOk();

        $this->as($this->reporter);
        $this->getJson("/api/restaurants/{$id}")->assertNotFound();
        $this->getJson('/api/admin/restaurants?status=deactivated', $this->as($this->admin))
            ->assertJsonPath('data.restaurants.0.deactivation_reason', 'Fake photos')
            ->assertJsonPath('data.counts.active', 0);

        $this->postJson("/api/admin/restaurants/{$id}/activate", [], $this->as($this->admin))->assertOk();
        $this->as($this->reporter);
        $this->getJson("/api/restaurants/{$id}")->assertOk();
        $this->getJson('/api/admin/activity', $this->as($this->admin))
            ->assertJsonPath('data.actions.0.action', 'activate')
            ->assertJsonPath('data.actions.1.action', 'deactivate');
    }

    public function test_banned_user_is_told_why_on_login(): void
    {
        $this->sokha->forceFill([
            'banned_at' => now(),
            'banned_until' => now()->addDays(7),
            'ban_reason' => 'spam',
        ])->save();

        $this->postJson('/api/auth/login', ['email' => $this->sokha->email, 'password' => 'secret123'])
            ->assertForbidden()
            ->assertJsonPath('meta.banned', true)
            ->assertJsonFragment(['message' => 'Your account is banned until '
                .now('Asia/Phnom_Penh')->addDays(7)->format('j M Y').' for spam or scams.']);
    }
}
