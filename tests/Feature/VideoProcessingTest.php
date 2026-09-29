<?php

namespace Tests\Feature;

use App\Jobs\ProcessRestaurantVideo;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\VideoReview;
use App\Services\VideoTranscoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VideoProcessingTest extends TestCase
{
    use RefreshDatabase;

    private VideoTranscoder $transcoder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transcoder = new VideoTranscoder;
        Storage::fake('local');
        Storage::fake('public');
    }

    private function probe(array $video, array $audio = ['codec_type' => 'audio', 'codec_name' => 'aac'], string $format = 'mov,mp4,m4a,3gp,3g2,mj2'): array
    {
        return [
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p', 'width' => 720, 'height' => 1280,
                    'avg_frame_rate' => '30000/1001', 'bit_rate' => '2500000', ...$video],
                $audio,
            ],
            'format' => ['format_name' => $format, 'bit_rate' => '2600000'],
        ];
    }

    public function test_app_compressed_upload_is_only_remuxed(): void
    {
        $this->assertSame(VideoTranscoder::REMUX, VideoTranscoder::plan($this->probe([]))['mode']);
        // Portrait 1080p (phones store it as 1920x1080 plus rotation) is fine.
        $this->assertSame(VideoTranscoder::REMUX, VideoTranscoder::plan($this->probe(['width' => 1920, 'height' => 1080]))['mode']);
    }

    public function test_anything_outside_the_limits_is_encoded(): void
    {
        foreach ([
            'hevc' => $this->probe(['codec_name' => 'hevc']),
            '4k' => $this->probe(['width' => 2160, 'height' => 3840]),
            '4:3 too wide' => $this->probe(['width' => 1440, 'height' => 1920]),
            '60fps' => $this->probe(['avg_frame_rate' => '60/1']),
            'high bitrate' => $this->probe(['bit_rate' => '15000000']),
            '10-bit' => $this->probe(['pix_fmt' => 'yuv420p10le']),
            'opus audio' => $this->probe([], ['codec_type' => 'audio', 'codec_name' => 'opus']),
            'webm' => $this->probe([], format: 'matroska,webm'),
        ] as $case => $probe) {
            $this->assertSame(VideoTranscoder::ENCODE, VideoTranscoder::plan($probe)['mode'], $case);
        }
    }

    public function test_video_url_follows_the_delivery_setting_for_old_and_new_rows(): void
    {
        $old = new VideoReview(['video_url' => 'http://192.168.1.235:8000/api/videos/stream/abc.mp4']);
        $new = new VideoReview(['video_url' => 'reviews/videos/abc.mp4']);

        config(['video.stream_through_app' => false]);
        $this->assertSame(Storage::disk('public')->url('reviews/videos/abc.mp4'), $old->video_url);
        $this->assertSame(Storage::disk('public')->url('reviews/videos/abc.mp4'), $new->video_url);

        config(['video.stream_through_app' => true]);
        $this->assertSame(route('videos.stream', ['filename' => 'abc.mp4']), $new->video_url);
        $this->assertNull((new VideoReview)->video_url);
    }

    // ---- End to end with real ffmpeg -------------------------------------

    private function makeClip(string $name, string $size, int $fps, array $codec): string
    {
        $path = 'reviews/temp/'.$name;
        Storage::disk('local')->makeDirectory('reviews/temp');
        $full = Storage::disk('local')->path($path);

        Process::run([
            $this->ffmpegBinary(), '-y', '-v', 'error',
            '-f', 'lavfi', '-i', "testsrc2=size={$size}:rate={$fps}:duration=2",
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=2',
            ...$codec, '-shortest', $full,
        ])->throw();

        return $path;
    }

    private function ffmpegBinary(): string
    {
        foreach (['/opt/homebrew/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/usr/bin/ffmpeg'] as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        $this->markTestSkipped('ffmpeg is not installed');
    }

    private function process(string $tempPath): VideoReview
    {
        $user = User::factory()->create(['username' => 'uploader']);
        $restaurant = Restaurant::create(['name' => 'Angkor Bites', 'status' => 'active']);
        $video = VideoReview::create([
            'user_id' => $user->id,
            'restaurant_id' => $restaurant->id,
            'type' => VideoReview::TYPE_REVIEW,
            'status' => 'processing',
        ]);

        (new ProcessRestaurantVideo($video, $tempPath))->handle($this->transcoder);

        return $video->fresh();
    }

    private function outputStream(VideoReview $video): array
    {
        $file = Storage::disk('public')->path('reviews/videos/'.basename($video->getRawOriginal('video_url')));
        $probe = $this->transcoder->probe($file);

        return collect($probe['streams'])->firstWhere('codec_type', 'video');
    }

    public function test_phone_compressed_clip_is_remuxed_end_to_end(): void
    {
        $temp = $this->makeClip('phone.mp4', '720x1280', 30, [
            '-c:v', 'libx264', '-preset', 'ultrafast', '-b:v', '1500k', '-pix_fmt', 'yuv420p', '-c:a', 'aac',
        ]);

        $video = $this->process($temp);

        $this->assertSame('ready', $video->status);
        $stream = $this->outputStream($video);
        $this->assertSame([720, 1280], [$stream['width'], $stream['height']]);
        $this->assertTrue(Storage::disk('public')->exists('reviews/thumbnails/phone.jpg'));
        $this->assertSame(2, $video->duration_seconds);
        $this->assertFalse(Storage::disk('local')->exists($temp));
    }

    public function test_4k_60fps_portrait_clip_is_encoded_once_to_1080p30(): void
    {
        $temp = $this->makeClip('big.mp4', '2160x3840', 60, [
            '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac',
        ]);

        $video = $this->process($temp);

        $this->assertSame('ready', $video->status);
        $stream = $this->outputStream($video);
        $this->assertSame([1080, 1920], [$stream['width'], $stream['height']]);
        $this->assertSame('30/1', $stream['avg_frame_rate']);
        $this->assertSame('h264', $stream['codec_name']);
    }
}
