<?php

namespace App\Events;

use App\Models\VideoComment;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired the instant a comment is posted. ShouldBroadcastNow (not queued) —
 * broadcasting is a single fast HTTP call to Reverb, and this is a chat-like
 * feature where latency matters more than the durability a queued job gives.
 */
class CommentCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * $comment must already have its `user` relation loaded by the caller.
     */
    public function __construct(public VideoComment $comment) {}

    public function broadcastOn(): array
    {
        return [new Channel('video.'.$this->comment->video_review_id.'.comments')];
    }

    public function broadcastAs(): string
    {
        return 'comment.new';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->comment->id,
            'video_review_id' => $this->comment->video_review_id,
            'body' => $this->comment->body,
            'user' => $this->comment->user,
            'likes_count' => 0,
            // Broadcast is a single fan-out to every viewer — "liked by me"
            // is inherently per-viewer, so it's always false here. Each
            // client already knows its own like state for comments it
            // posted or liked itself from the REST response that action got.
            'is_liked' => false,
            'created_at' => $this->comment->created_at?->toJSON(),
        ];
    }
}
