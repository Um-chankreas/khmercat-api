<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Turns an uploaded video into the MP4 the feed serves, doing as little work
 * as possible: uploads the app already compressed are usually fine as-is and
 * only get remuxed (streams copied, moov atom moved to the front); anything
 * else is encoded exactly once to the limits in config/video.php.
 */
class VideoTranscoder
{
    public const REMUX = 'remux';

    public const ENCODE = 'encode';

    private string $ffmpeg;

    private string $ffprobe;

    public function __construct()
    {
        $this->ffmpeg = self::binary('ffmpeg');
        $this->ffprobe = self::binary('ffprobe');
    }

    /**
     * ffprobe's JSON (`streams` + `format`) for [path].
     */
    public function probe(string $path): array
    {
        $result = Process::timeout(60)->run([
            $this->ffprobe, '-v', 'error', '-print_format', 'json',
            '-show_streams', '-show_format', $path,
        ]);

        if ($result->failed()) {
            throw new RuntimeException('ffprobe failed: '.$result->errorOutput());
        }

        return json_decode($result->output(), true) ?: [];
    }

    /**
     * Whether [probe] can be remuxed or needs an encode, and why — the
     * reasons are logged so it's clear why an upload took the slow path.
     *
     * @return array{mode: string, reasons: list<string>, fps: float}
     */
    public static function plan(array $probe): array
    {
        $video = collect($probe['streams'] ?? [])->firstWhere('codec_type', 'video');
        $audio = collect($probe['streams'] ?? [])->firstWhere('codec_type', 'audio');
        $fps = self::fps($video);

        if (! $video) {
            return ['mode' => self::ENCODE, 'reasons' => ['no video stream found'], 'fps' => $fps];
        }

        $width = (int) ($video['width'] ?? 0);
        $height = (int) ($video['height'] ?? 0);
        $kbps = (int) (($video['bit_rate'] ?? $probe['format']['bit_rate'] ?? 0) / 1000);

        $reasons = array_values(array_filter([
            str_contains($probe['format']['format_name'] ?? '', 'mp4') ? null : 'container is not MP4/MOV',
            ($video['codec_name'] ?? '') === 'h264' ? null : 'video codec is '.($video['codec_name'] ?? 'unknown'),
            ($video['pix_fmt'] ?? '') === 'yuv420p' ? null : 'pixel format is '.($video['pix_fmt'] ?? 'unknown'),
            max($width, $height) <= config('video.max_long_side')
                && min($width, $height) <= config('video.max_short_side')
                ? null : "resolution {$width}x{$height} is too large",
            $fps <= config('video.max_fps') + 1 ? null : "frame rate {$fps} is too high",
            $kbps > 0 && $kbps <= config('video.max_video_kbps') ? null : "bitrate {$kbps} kbps is too high or unknown",
            ! $audio || ($audio['codec_name'] ?? '') === 'aac' ? null : 'audio codec is '.($audio['codec_name'] ?? 'unknown'),
        ]));

        return ['mode' => $reasons ? self::ENCODE : self::REMUX, 'reasons' => $reasons, 'fps' => $fps];
    }

    /**
     * Copies the streams into a fresh MP4 with `faststart`, so playback can
     * begin before the whole file has downloaded.
     */
    public function remux(string $input, string $output): void
    {
        $this->ffmpeg([
            '-i', $input,
            '-map', '0:v:0', '-map', '0:a:0?',
            '-c', 'copy',
            '-movflags', '+faststart',
            $output,
        ], timeout: 120);
    }

    /**
     * One encode down to the configured limits. Scaling caps the long and
     * short sides separately, so portrait video isn't treated as landscape,
     * and never upscales.
     */
    public function encode(string $input, string $output, float $sourceFps): void
    {
        $long = config('video.max_long_side');
        $short = config('video.max_short_side');
        $maxFps = config('video.max_fps');

        $filters = [
            "scale=w='if(gte(iw,ih),min({$long},iw),min({$short},iw))'"
                .":h='if(gte(iw,ih),min({$short},ih),min({$long},ih))'"
                .':force_original_aspect_ratio=decrease:force_divisible_by=2:flags=lanczos',
        ];
        if ($sourceFps > $maxFps + 1) {
            $filters[] = "fps={$maxFps}";
        }
        $fps = $sourceFps > 0 ? min($sourceFps, $maxFps) : $maxFps;

        $this->ffmpeg([
            '-i', $input,
            '-map', '0:v:0', '-map', '0:a:0?',
            '-vf', implode(',', $filters),
            '-c:v', 'libx264',
            '-preset', config('video.preset'),
            '-crf', (string) config('video.crf'),
            '-maxrate', config('video.maxrate'),
            '-bufsize', config('video.bufsize'),
            '-profile:v', 'high',
            '-level', '4.1',
            '-pix_fmt', 'yuv420p',
            // A keyframe every ~2s: quicker start and seeking in the player.
            '-g', (string) (int) round($fps * 2),
            '-c:a', 'aac',
            '-b:a', config('video.audio_kbps').'k',
            '-ac', '2',
            '-movflags', '+faststart',
            '-threads', (string) $this->encoderThreads(),
            $output,
        ], timeout: 1800);
    }

    /**
     * A JPEG poster frame, at most 720px wide. Taken at 1s, or halfway
     * through clips shorter than 2s so very short clips still get one.
     */
    public function thumbnail(string $input, string $output, ?float $duration): void
    {
        $at = $duration !== null && $duration < 2 ? $duration / 2 : 1;

        $this->ffmpeg([
            '-ss', (string) $at,
            '-i', $input,
            '-frames:v', '1',
            '-vf', "scale='min(720,iw)':-2",
            '-q:v', '3',
            $output,
        ], timeout: 60);
    }

    private function ffmpeg(array $args, int $timeout): void
    {
        $result = Process::timeout($timeout)->run([$this->ffmpeg, '-y', '-v', 'error', ...$args]);

        if ($result->failed()) {
            throw new RuntimeException('ffmpeg failed: '.trim($result->errorOutput()));
        }
    }

    /**
     * Frames per second from ffprobe's "30000/1001"-style rate, 0 if unknown.
     */
    private static function fps(?array $video): float
    {
        foreach (['avg_frame_rate', 'r_frame_rate'] as $key) {
            [$num, $den] = array_pad(explode('/', (string) ($video[$key] ?? '')), 2, 1);
            if ((float) $num > 0 && (float) $den > 0) {
                return round((float) $num / (float) $den, 2);
            }
        }

        return 0;
    }

    /**
     * Leave one CPU core free so nginx / PHP-FPM keep serving the app while
     * this encodes — with every core busy a 2-core server stalls everything
     * until the encode finishes.
     */
    private function encoderThreads(): int
    {
        $cores = (int) trim((string) @shell_exec('nproc'));

        return max(1, $cores - 1);
    }

    private static function binary(string $name): string
    {
        foreach (["/opt/homebrew/bin/{$name}", "/usr/local/bin/{$name}"] as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return "/usr/bin/{$name}";
    }
}
