<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * $actor followed the notifiable user. Delivered via the `database` channel
 * (for the persistent notifications list) and `broadcast` (Reverb, for the
 * real-time badge/list update) — no `toBroadcast()` override needed, the
 * default broadcast behavior already uses `toArray()`.
 */
class UserFollowed extends Notification
{
    public function __construct(public User $actor) {}

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
        return [
            'type' => 'follow',
            'actor' => [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
                'username' => $this->actor->username,
                'profile_picture' => $this->actor->profile_picture,
            ],
        ];
    }
}
