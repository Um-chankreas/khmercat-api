<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class CommentDeleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $videoReviewId,
        public int $commentId,
        public ?int $parentId = null,
        public int $removedCount = 1,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('video.'.$this->videoReviewId.'.comments')];
    }

    public function broadcastAs(): string
    {
        return 'comment.deleted';
    }

    public function broadcastWith(): array
    {
        return [
            'comment_id' => $this->commentId,
            // Set when a reply was deleted, so clients can update its thread.
            'parent_id' => $this->parentId,
            // The comment plus any replies deleted along with it.
            'removed_count' => $this->removedCount,
        ];
    }
}
