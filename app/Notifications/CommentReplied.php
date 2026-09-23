<?php

namespace App\Notifications;

use App\Models\VideoComment;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class CommentReplied extends Notification
{
    public function __construct(public VideoComment $reply, public VideoComment $parent) {}

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
        $actor = $this->reply->user;

        return [
            'type' => 'reply',
            'actor' => [
                'id' => $actor->id,
                'name' => $actor->name,
                'username' => $actor->username,
                'profile_picture' => $actor->profile_picture,
            ],
            'video' => [
                'id' => $this->reply->video_review_id,
            ],
            'comment' => [
                'id' => $this->reply->id,
                'parent_id' => $this->parent->id,
                'preview' => Str::limit($this->reply->body, 80),
            ],
        ];
    }
}
