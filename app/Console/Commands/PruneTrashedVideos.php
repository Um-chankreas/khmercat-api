<?php

namespace App\Console\Commands;

use App\Models\VideoReview;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Permanently deletes video reviews that have sat in the trash (soft-deleted
 * via the profile "Delete" tab) for more than 30 days, along with their
 * stored video/thumbnail files. Child rows (likes, saves, comments, hashtag
 * links) cascade via their foreign keys.
 */
class PruneTrashedVideos extends Command
{
    protected $signature = 'videos:prune-trashed';

    protected $description = 'Permanently delete video reviews that have been in the trash for more than 30 days';

    public function handle(): int
    {
        $videos = VideoReview::onlyTrashed()
            ->where('deleted_at', '<=', now()->subDays(30))
            ->get();

        foreach ($videos as $video) {
            $this->deleteStoredFile($video->video_url, 'reviews/videos');
            $this->deleteStoredFile($video->thumbnail_url, 'reviews/thumbnails');

            $video->forceDelete();
        }

        $this->info("Permanently deleted {$videos->count()} trashed video review(s).");

        return self::SUCCESS;
    }

    private function deleteStoredFile(?string $url, string $directory): void
    {
        if (! $url) {
            return;
        }

        $filename = basename(parse_url($url, PHP_URL_PATH) ?: '');
        $path = $directory.'/'.$filename;

        if ($filename !== '' && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
