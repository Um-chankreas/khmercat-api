<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use App\Models\VideoReview;
use App\Notifications\CommentReplied;
use App\Notifications\UserFollowed;
use App\Notifications\VideoLiked;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class NotificationListTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $fan;

    private VideoReview $video;

    protected function setUp(): void
    {
        parent::setUp();
        $this->me = User::factory()->create(['username' => 'me']);
        $this->fan = User::factory()->create(['username' => 'fan']);
        $restaurant = Restaurant::create(['name' => 'R', 'status' => 'active']);
        $this->video = VideoReview::create([
            'user_id' => $this->me->id,
            'restaurant_id' => $restaurant->id,
            'type' => VideoReview::TYPE_REVIEW,
            'status' => 'ready',
            'video_url' => 'http://x/v.mp4',
            'thumbnail_url' => 'http://x/t.jpg',
        ]);
    }

    /** Stores the notification the way the database channel would. */
    private function store(Notification $n, bool $read = false): string
    {
        $id = (string) Str::uuid();
        $this->me->notifications()->create([
            'id' => $id,
            'type' => get_class($n),
            'data' => $n->toArray($this->me),
            'read_at' => $read ? now() : null,
        ]);

        return $id;
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($this->me)];
    }

    public function test_filters_unread_counts_follow_state_and_thumbnails(): void
    {
        $this->store(new VideoLiked($this->video, $this->fan));
        $this->store(new VideoLiked($this->video, $this->fan), read: true);
        $this->store(new UserFollowed($this->fan));
        $top = $this->video->comments()->create(['user_id' => $this->me->id, 'body' => 'hi']);
        $reply = $this->video->comments()->create(['user_id' => $this->fan->id, 'parent_id' => $top->id, 'body' => 'yo']);
        $this->store(new CommentReplied($reply->load('user'), $top));
        $this->me->follow($this->fan);

        $all = $this->getJson('/api/notifications', $this->auth())->assertOk();
        $this->assertSame(4, $all->json('data.meta.total'));
        $this->assertSame(
            ['all' => 3, 'likes' => 1, 'comments' => 1, 'follows' => 1, 'updates' => 0],
            $all->json('data.meta.unread')
        );

        $likes = $this->getJson('/api/notifications?filter=likes', $this->auth())->assertOk();
        $this->assertSame(['like', 'like'], array_column($likes->json('data.contents'), 'type'));

        $follows = $this->getJson('/api/notifications?filter=follows', $this->auth())->json('data.contents');
        $this->assertTrue($follows[0]['actor']['is_following']);

        $comments = $this->getJson('/api/notifications?filter=comments', $this->auth())->json('data.contents');
        $this->assertSame('http://x/t.jpg', $comments[0]['video']['thumbnail_url']);
    }

    public function test_mark_many_read_and_delete_many(): void
    {
        $a = $this->store(new VideoLiked($this->video, $this->fan));
        $b = $this->store(new VideoLiked($this->video, $this->fan));
        $c = $this->store(new UserFollowed($this->fan));

        $this->postJson('/api/notifications/read', ['ids' => [$a, $b]], $this->auth())
            ->assertOk()->assertJsonPath('data.updated', 2);
        $this->assertSame(1, $this->me->unreadNotifications()->count());

        $this->deleteJson('/api/notifications/batch', ['ids' => [$a, $c]], $this->auth())
            ->assertOk()->assertJsonPath('data.deleted', 2);
        $this->assertSame([$b], $this->me->notifications()->pluck('id')->all());
    }
}
