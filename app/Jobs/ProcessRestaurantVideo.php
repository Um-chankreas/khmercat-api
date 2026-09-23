<?php

namespace App\Jobs;

use App\Models\VideoReview;
use App\Notifications\RestaurantPostedVideo;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;
use FFMpeg\Format\Video\X264;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessRestaurantVideo implements ShouldQueue
{
    use Queueable;

    public $timeout = 600;

    public function __construct(
        public VideoReview $videoReview,
        public string $tempFilePath
    ) {}

    public function handle(): void
    {
        // 1. Detect system binary paths
        $ffmpegPath = match (true) {
            file_exists('/opt/homebrew/bin/ffmpeg') => '/opt/homebrew/bin/ffmpeg',
            file_exists('/usr/local/bin/ffmpeg') => '/usr/local/bin/ffmpeg',
            default => '/usr/bin/ffmpeg',
        };

        $ffprobePath = match (true) {
            file_exists('/opt/homebrew/bin/ffprobe') => '/opt/homebrew/bin/ffprobe',
            file_exists('/usr/local/bin/ffprobe') => '/usr/local/bin/ffprobe',
            default => '/usr/bin/ffprobe',
        };

        // 2. Resolve absolute file path
        $rawStoragePath = Storage::disk('local')->path($this->tempFilePath);
        $fullPath = realpath($rawStoragePath);

        if (! $fullPath || ! file_exists($fullPath)) {
            Log::error("Video processing failed: File not found at {$rawStoragePath}");
            $this->videoReview->update(['status' => 'failed']);

            return;
        }

        // 3. Capture original file size early
        $originalSize = Storage::disk('local')->exists($this->tempFilePath)
            ? (int) Storage::disk('local')->size($this->tempFilePath)
            : 0;

        Log::info('Starting video compression - Original size: '.($originalSize / 1024 / 1024).' MB');

        // 4. Initialize FFmpeg
        $ffmpeg = FFMpeg::create([
            'ffmpeg.binaries' => $ffmpegPath,
            'ffprobe.binaries' => $ffprobePath,
            'timeout' => 3600,
            'ffmpeg.threads' => 0, // Auto-detect and use maximum available CPU cores
        ]);

        $video = $ffmpeg->open($fullPath);
        $baseName = pathinfo($this->tempFilePath, PATHINFO_FILENAME);

        // 5. Generate Thumbnail Frame (at 1 second mark)
        Storage::disk('public')->makeDirectory('reviews/thumbnails');
        $thumbnailRelativePath = 'reviews/thumbnails/'.$baseName.'.jpg';
        $thumbnailFullPath = Storage::disk('public')->path($thumbnailRelativePath);

        $frame = $video->frame(TimeCode::fromSeconds(1));
        $frame->save($thumbnailFullPath);
        Log::info("Thumbnail generated: {$thumbnailRelativePath}");

        // 6. High-Quality Video Compression Setup
        Storage::disk('public')->makeDirectory('reviews/videos');
        $compressedRelativePath = 'reviews/videos/'.$baseName.'.mp4';
        $compressedFullPath = Storage::disk('public')->path($compressedRelativePath);

        $qualityProfile = $this->getQualityProfile($originalSize);
        $sizeInMB = $originalSize / (1024 * 1024);
        Log::info("Using quality profile for {$sizeInMB} MB video - CRF: {$qualityProfile['crf']}, Preset: {$qualityProfile['preset']}");

        $format = new X264('aac');
        $format->setAudioKiloBitrate($qualityProfile['audio']); // Use profile-based audio bitrate

        $format->setAdditionalParameters([
            '-crf', $qualityProfile['crf'],
            '-preset', $qualityProfile['preset'],
            '-profile:v', 'high',
            '-level', '4.2',
            '-maxrate', $qualityProfile['maxrate'],
            '-bufsize', $qualityProfile['bufsize'],
            '-movflags', 'faststart',
            '-pix_fmt', 'yuv420p',
            '-vf', "scale='min(1920,iw)':-2:flags=lanczos",
        ]);

        // Save compressed video
        $video->save($format, $compressedFullPath);
        Log::info("Initial compression completed for: {$compressedRelativePath}");

        // 7. Calculate file sizes and compression ratio
        $compressedSize = Storage::disk('public')->exists($compressedRelativePath)
            ? (int) Storage::disk('public')->size($compressedRelativePath)
            : 0;

        $compressionRatio = $originalSize > 0 ? ($compressedSize / $originalSize) * 100 : 0;
        $compressedMB = $compressedSize / (1024 * 1024);

        Log::info("Compression ratio: {$compressionRatio}% (Original: {$sizeInMB} MB → Compressed: {$compressedMB} MB)");

        // 8. Validate compression and re-compress if it didn't actually help,
        // regardless of the original file's size.
        if ($originalSize > 0 && $compressionRatio >= 90) {
            Log::warning("Poor compression detected ({$compressionRatio}%), re-compressing with lower quality...");
            $this->recompressVideo($ffmpegPath, $ffprobePath, $fullPath, $compressedFullPath);

            $compressedSize = Storage::disk('public')->exists($compressedRelativePath)
                ? (int) Storage::disk('public')->size($compressedRelativePath)
                : 0;

            $compressionRatio = $originalSize > 0 ? ($compressedSize / $originalSize) * 100 : 0;
            $compressedMB = $compressedSize / (1024 * 1024);
            Log::info("Re-compression completed - New ratio: {$compressionRatio}% (Compressed: {$compressedMB} MB)");
        }

        // 9. Update Database Record
        // video_url deliberately goes through the videos.stream route rather
        // than the raw /storage/... static path: PHP's built-in dev server
        // (php artisan serve) doesn't support HTTP Range requests for static
        // files, which most mobile video players require to play at all.
        // The stream route goes through Laravel/Symfony's response pipeline,
        // which handles Range/206 properly even under the dev server.
        $this->videoReview->update([
            'video_url' => route('videos.stream', ['filename' => $baseName.'.mp4']),
            'thumbnail_url' => Storage::disk('public')->url($thumbnailRelativePath),
            'original_size' => $originalSize,
            'compressed_size' => $compressedSize,
            'compression_ratio' => round($compressionRatio, 2),
            'status' => 'ready',
        ]);

        Log::info("Video processing completed successfully for video review ID: {$this->videoReview->id}");

        $this->notifyFollowersOfNewReview();

        // 10. Clean up temporary upload file
        if (Storage::disk('local')->exists($this->tempFilePath)) {
            Storage::disk('local')->delete($this->tempFilePath);
            Log::info("Temporary file cleaned up: {$this->tempFilePath}");
        }
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
                $follower->notify(new RestaurantPostedVideo($restaurant, $this->videoReview));
            }
        }
    }

    /**
     * Get compression quality profile based on original file size
     */
    private function getQualityProfile(int $originalSizeBytes): array
    {
        $sizeInMB = $originalSizeBytes / (1024 * 1024);

        return match (true) {
            $sizeInMB < 50 => [      // Small videos - high quality, still real compression
                'crf' => 20,
                'preset' => 'slow',
                'audio' => 160,
                'maxrate' => '8M',
                'bufsize' => '12M',
            ],
            $sizeInMB < 200 => [     // Medium videos - balanced
                'crf' => 23,
                'preset' => 'medium',
                'audio' => 128,
                'maxrate' => '6M',
                'bufsize' => '9M',
            ],
            default => [             // Large videos - prioritize compression
                'crf' => 26,
                'preset' => 'fast',
                'audio' => 96,
                'maxrate' => '4M',
                'bufsize' => '6M',
            ],
        };
    }

    /**
     * Re-compress video with lower quality settings if initial compression wasn't effective.
     * Re-encodes from the original source (not the first-pass output) into $outputPath —
     * re-encoding an already-lossy-compressed file loses quality for no benefit, and ffmpeg
     * refuses to use the same path for input and output anyway.
     */
    private function recompressVideo(string $ffmpegPath, string $ffprobePath, string $sourcePath, string $outputPath): void
    {
        try {
            $ffmpeg = FFMpeg::create([
                'ffmpeg.binaries' => $ffmpegPath,
                'ffprobe.binaries' => $ffprobePath,
                'timeout' => 3600,
                'ffmpeg.threads' => 0,
            ]);

            $video = $ffmpeg->open($sourcePath);
            $format = new X264('aac');
            $format->setAudioKiloBitrate(96);

            $format->setAdditionalParameters([
                '-crf', '28',                           // Meaningfully more aggressive than any first-pass profile
                '-preset', 'fast',                      // Faster processing
                '-profile:v', 'main',                   // Smaller profile
                '-level', '4.0',
                '-maxrate', '3M',                       // Lower bitrate ceiling
                '-bufsize', '5M',
                '-movflags', 'faststart',
                '-pix_fmt', 'yuv420p',
                '-vf', "scale='min(1280,iw)':-2",      // Reduce to 1280p
            ]);

            $video->save($format, $outputPath);
            Log::info('Video re-compressed successfully');
        } catch (Throwable $exception) {
            Log::error('Re-compression failed: '.$exception->getMessage());
            throw $exception;
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
