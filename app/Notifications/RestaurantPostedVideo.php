<?php

namespace App\Notifications;

use App\Models\Restaurant;
use App\Models\VideoReview;
use Illuminate\Notifications\Notification;

/**
 * Fanned out to every follower of $restaurant once its new video has
 * actually finished processing and is watchable (dispatched from
 * ProcessRestaurantVideo, not at upload time).
 */
class RestaurantPostedVideo extends Notification
{
    public function __construct(public Restaurant $restaurant, public VideoReview $video) {}

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
            'type' => 'restaurant_video',
            'restaurant' => [
                'id' => $this->restaurant->id,
                'name' => $this->restaurant->name,
                'profile_picture' => $this->restaurant->profile_picture,
            ],
            'video' => [
                'id' => $this->video->id,
                'thumbnail_url' => $this->video->thumbnail_url,
            ],
        ];
    }
}
