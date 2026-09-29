<?php

namespace Tests\Feature;

use App\Events\CommentCreated;
use App\Events\CommentDeleted;
use App\Events\CommentLiked;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\VideoComment;
use App\Models\VideoReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class CommentThreadTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    private User $fan;

    private VideoReview $video;

    protected function setUp(): void
    {
        parent::setUp();
        if ($this->name() !== 'test_posting_still_succeeds_when_broadcasting_fails') {
            Event::fake([CommentCreated::class, CommentDeleted::class, CommentLiked::class]);
            Notification::fake();
        }

        $this->creator = User::factory()->create(['username' => 'creator', 'is_verified' => true]);
        $this->fan = User::factory()->create(['username' => 'fan']);
        $restaurant = Restaurant::create(['name' => 'R', 'status' => 'active']);
        $this->video = VideoReview::create([
            'user_id' => $this->creator->id,
            'restaurant_id' => $restaurant->id,
            'type' => VideoReview::TYPE_REVIEW,
            'status' => 'ready',
            'video_url' => 'http://x/v.mp4',
        ]);
    }

    private function as(User $user): array
    {
        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }

    private function comment(User $user, ?int $parentId = null, string $body = 'hi'): VideoComment
    {
        return $this->video->comments()->create(['user_id' => $user->id, 'parent_id' => $parentId, 'body' => $body]);
    }

    public function test_index_nests_replies_and_flags_creator(): void
    {
        $top = $this->comment($this->fan, null, 'Is it open Sundays?');
        foreach (range(1, 4) as $i) {
            $this->comment($i === 1 ? $this->creator : $this->fan, $top->id, "reply {$i}");
        }

        $res = $this->getJson("/api/videos/{$this->video->id}/comments")->assertOk();

        $this->assertSame(1, $res->json('data.meta.total'));
        $this->assertSame(5, $res->json('data.meta.total_all'));

        $c = $res->json('data.contents.0');
        $this->assertSame('Is it open Sundays?', $c['body']);
        $this->assertFalse($c['is_creator']);
        $this->assertSame(4, $c['replies_count']);
        $this->assertCount(3, $c['replies']);
        $this->assertSame('reply 1', $c['replies'][0]['body']); // oldest first
        $this->assertTrue($c['replies'][0]['is_creator']);
        $this->assertTrue($c['replies'][0]['user']['is_verified']);

        $more = $this->getJson("/api/comments/{$top->id}/replies?per_page=3&page=2")->assertOk();
        $this->assertSame(['reply 4'], array_column($more->json('data.contents'), 'body'));
        $this->assertFalse($more->json('data.meta.has_more'));
    }

    public function test_reply_to_a_reply_stays_one_level_deep(): void
    {
        $top = $this->comment($this->fan);
        $reply = $this->comment($this->creator, $top->id);

        $res = $this->postJson(
            "/api/videos/{$this->video->id}/comments",
            ['body' => '@creator thanks!', 'parent_id' => $reply->id],
            $this->as($this->fan)
        )->assertCreated();

        $this->assertSame($top->id, $res->json('data.parent_id'));
        $this->assertFalse($res->json('data.is_creator'));
        Event::assertDispatched(CommentCreated::class, fn ($e) => $e->broadcastWith()['parent_id'] === $top->id);
    }

    public function test_deleting_a_comment_reports_removed_replies(): void
    {
        $top = $this->comment($this->fan);
        $this->comment($this->creator, $top->id);

        $this->deleteJson("/api/comments/{$top->id}", [], $this->as($this->fan))->assertOk();

        Event::assertDispatched(CommentDeleted::class, fn ($e) => $e->broadcastWith()['removed_count'] === 2
            && $e->broadcastWith()['parent_id'] === null);
        $this->assertSame(0, VideoComment::count());
    }

    public function test_posting_still_succeeds_when_broadcasting_fails(): void
    {
        // Reverb "down": broadcasting (the comment event and the creator's
        // notification) points at a port nothing listens on.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 1,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);

        $this->postJson(
            "/api/videos/{$this->video->id}/comments",
            ['body' => 'still works'],
            $this->as($this->fan)
        )->assertCreated();

        $this->assertSame(1, VideoComment::where('body', 'still works')->count());
    }
}
