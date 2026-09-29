<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stream videos through the app
    |--------------------------------------------------------------------------
    |
    | When true, video URLs point at the `videos.stream` route, which serves
    | the file through PHP. Only needed for `php artisan serve`, whose static
    | file server doesn't support the HTTP Range requests mobile players need.
    |
    | In production leave this false: URLs point straight at /storage/..., so
    | nginx serves the file itself (Range + sendfile) and no PHP-FPM worker is
    | tied up for as long as someone is watching.
    |
    */

    'stream_through_app' => (bool) env('VIDEO_STREAM_THROUGH_APP', env('APP_ENV') === 'local'),

    /*
    |--------------------------------------------------------------------------
    | Delivery limits
    |--------------------------------------------------------------------------
    |
    | Uploads already within all of these (and H.264/AAC in an MP4/MOV) are
    | only remuxed — no re-encode, no quality loss, done in about a second.
    | Anything else is encoded once down to them.
    |
    */

    'max_long_side' => 1920,
    'max_short_side' => 1080,
    'max_fps' => 30,
    'max_video_kbps' => 6000,

    // Encode settings for uploads that don't qualify for a remux.
    'crf' => 23,
    'preset' => 'faster',
    'maxrate' => '5M',
    'bufsize' => '10M',
    'audio_kbps' => 128,

];
