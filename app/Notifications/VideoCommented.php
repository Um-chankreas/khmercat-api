<?php

namespace App\Notifications;

use App\Models\VideoComment;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Someone commented on the notifiable's review video (top-level comment,
 * not a reply — see CommentReplied for that case).
 */
class VideoCommented extends Notification
{
    public function __construct(public VideoComment $comment) {}

    public function broadcastType(): string
    {
        return 'notification';
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        $actor = $this->comment->user;
        $video = $this->comment->videoReview;

        return [
            'type' => 'comment',
            'actor' => [
                'id' => $actor->id,
                'name' => $actor->name,
                'username' => $actor->username,
                'profile_picture' => $actor->profile_picture,
            ],
            'video' => [
                'id' => $video->id,
                'thumbnail_url' => $video->thumbnail_url,
            ],
            'comment' => [
                'id' => $this->comment->id,
                'preview' => Str::limit($this->comment->body, 80),
            ],
        ];
    }
}
