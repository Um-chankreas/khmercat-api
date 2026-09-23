<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;
use Throwable;

class PushNotificationService
{
    /**
     * Sends to every device this user has registered. No-ops if they have
     * none (never installed the app, or never granted notification
     * permission — device tokens are only ever registered client-side).
     *
     * Deliberately resolves the Firebase `Messaging` service lazily, from
     * the container, only once there's an actual token to send to — not via
     * constructor injection. Firebase credentials are still being set up
     * (see FIREBASE_CREDENTIALS), and until every user has re-registered a
     * device token there will regularly be nothing to send; eagerly
     * resolving `Messaging` would try to load those credentials on every
     * call regardless, hard-failing this (queued) job before it even gets
     * to check whether there's anyone to notify.
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): void
    {
        $tokens = $user->deviceTokens()->pluck('token')->all();

        if (empty($tokens)) {
            return;
        }

        $message = CloudMessage::new()
            ->withNotification(FcmNotification::create($title, $body))
            ->withData(array_map('strval', $data));

        try {
            $report = App::make(Messaging::class)->sendMulticast($message, $tokens);

            // A token goes invalid when the app is uninstalled or the OS
            // rotates it — stop retrying it forever once Firebase says so.
            DeviceToken::whereIn('token', $report->invalidTokens())->delete();
        } catch (Throwable $e) {
            Log::warning('Push notification send failed: '.$e->getMessage());
        }
    }
}
