<?php

namespace App\Jobs;

use App\Models\VideoReview;
use App\Notifications\RestaurantPostedVideo;
use App\Services\VideoTranscoder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessRestaurantVideo implements ShouldQueue
{
    use Queueable;

    // Covers a worst-case full encode (VideoTranscoder allows 1800s). Keep
    // below the queue's retry_after (config/queue.php).
    public $timeout = 1900;

    public function __construct(
        public VideoReview $videoReview,
        public string $tempFilePath
    ) {}

    public function handle(VideoTranscoder $transcoder): void
    {
        $sourcePath = Storage::disk('local')->path($this->tempFilePath);
        if (! file_exists($sourcePath)) {
            Log::error("Video processing failed: file not found at {$sourcePath}");
            $this->videoReview->update(['status' => 'failed']);

            return;
        }
        $originalSize = (int) filesize($sourcePath);

        $probe = $transcoder->probe($sourcePath);
        $duration = isset($probe['format']['duration']) ? (float) $probe['format']['duration'] : null;
        $baseName = pathinfo($this->tempFilePath, PATHINFO_FILENAME);

        $public = Storage::disk('public');
        $public->makeDirectory('reviews/thumbnails');
        $public->makeDirectory('reviews/videos');
        $thumbnailPath = 'reviews/thumbnails/'.$baseName.'.jpg';
        $videoPath = 'reviews/videos/'.$baseName.'.mp4';
        $videoFullPath = $public->path($videoPath);

        $transcoder->thumbnail($sourcePath, $public->path($thumbnailPath), $duration);

        // The app compresses before uploading, so most videos are already
        // fine and only need a remux; re-encoding those would cost minutes
        // of CPU and a little quality for nothing.
        $plan = VideoTranscoder::plan($probe);
        $mode = $plan['mode'];
        Log::info("Video {$this->videoReview->id}: {$mode}", ['reasons' => $plan['reasons']]);

        if ($mode === VideoTranscoder::REMUX) {
            try {
                $transcoder->remux($sourcePath, $videoFullPath);
            } catch (Throwable $e) {
                Log::warning("Remux failed, encoding instead: {$e->getMessage()}");
                $mode = VideoTranscoder::ENCODE;
            }
        }
        if ($mode === VideoTranscoder::ENCODE) {
            $transcoder->encode($sourcePath, $videoFullPath, $plan['fps']);
        }

        $compressedSize = (int) filesize($videoFullPath);
        $compressionRatio = $originalSize > 0 ? $compressedSize / $originalSize * 100 : 0;

        // video_url is resolved to its public URL when read (see
        // VideoReview::videoUrl), so the stored value only needs the file.
        $this->videoReview->update([
            'video_url' => $videoPath,
            'thumbnail_url' => $public->url($thumbnailPath),
            'original_size' => $originalSize,
            'compressed_size' => $compressedSize,
            'compression_ratio' => round($compressionRatio, 2),
            'duration_seconds' => $duration ? (int) round($duration) : null,
            'status' => 'ready',
        ]);

        Log::info(sprintf(
            'Video %d ready (%s): %.1f MB -> %.1f MB',
            $this->videoReview->id,
            $mode,
            $originalSize / 1048576,
            $compressedSize / 1048576,
        ));

        $this->notifyFollowersOfNewReview();

        Storage::disk('local')->delete($this->tempFilePath);
    }

    /**
     * Notifies the relevant followers once the video is actually watchable —
     * not at upload time, so nobody gets pinged about a video that's still
     * processing or fails compression. Who gets notified depends on type:
     * a review notifies the *uploader's* followers, a restaurant post
     * notifies the *restaurant's* followers.
     */
    private function notifyFollowersOfNewReview(): void
    {
        if ($this->videoReview->type === VideoReview::TYPE_REVIEW) {
            $uploader = $this->videoReview->user;

            foreach ($uploader->followerUsers() as $follower) {
                SendPushNotificationJob::dispatch(
                    $follower,
                    'New review',
                    "{$uploader->name} posted a new review.",
                    ['type' => 'new_review_video', 'video_id' => (string) $this->videoReview->id]
                );
            }

            return;
        }

        if ($this->videoReview->type === VideoReview::TYPE_RESTAURANT_POST) {
            $restaurant = $this->videoReview->restaurant;

            foreach ($restaurant->followerUsers() as $follower) {
                SendPushNotificationJob::dispatch(
                    $follower,
                    'New video',
                    "{$restaurant->name} posted a new video.",
                    ['type' => 'restaurant_video', 'video_id' => (string) $this->videoReview->id]
                );
                // The video is already saved as ready; a Reverb outage must
                // not fail (and retry) the whole processing job.
                try {
                    $follower->notify(new RestaurantPostedVideo($restaurant, $this->videoReview));
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Video processing failed: '.$exception->getMessage());

        $this->videoReview->update(['status' => 'failed']);

        if (Storage::disk('local')->exists($this->tempFilePath)) {
            Storage::disk('local')->delete($this->tempFilePath);
        }
    }
}
