<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcasts only the new count, never `is_liked` — whether a comment is
 * liked is per-viewer, and this one event fans out to every viewer at once.
 * The acting user's own `is_liked` comes back synchronously in the REST
 * response to their like/unlike request.
 */
class CommentLiked implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $videoReviewId,
        public int $commentId,
        public int $likesCount,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('video.'.$this->videoReviewId.'.comments')];
    }

    public function broadcastAs(): string
    {
        return 'comment.liked';
    }

    public function broadcastWith(): array
    {
        return [
            'comment_id' => $this->commentId,
            'likes_count' => $this->likesCount,
        ];
    }
}
