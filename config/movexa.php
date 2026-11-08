<?php

return [
    // Root of large data, OUTSIDE the site directory (aaPanel: /www/data/movexa).
    'allow_registration' => (bool) env('MOVEXA_ALLOW_REGISTRATION', false),

    'data_root' => env('MOVEXA_DATA_ROOT', '/www/data/movexa'),

    'paths' => [
        'inbox' => env('MOVEXA_INBOX', env('MOVEXA_DATA_ROOT', '/www/data/movexa').'/inbox'),
        'library' => env('MOVEXA_LIBRARY', env('MOVEXA_DATA_ROOT', '/www/data/movexa').'/library'),
        'quarantine' => env('MOVEXA_QUARANTINE', env('MOVEXA_DATA_ROOT', '/www/data/movexa').'/quarantine'),
        'cache' => env('MOVEXA_CACHE', env('MOVEXA_DATA_ROOT', '/www/data/movexa').'/cache'),
        'images' => env('MOVEXA_IMAGES', env('MOVEXA_DATA_ROOT', '/www/data/movexa').'/images'),
        'uploads' => env('MOVEXA_UPLOADS', env('MOVEXA_DATA_ROOT', '/www/data/movexa').'/uploads'),
    ],

    // xaccel: Laravel validates, nginx serves via X-Accel-Redirect (production).
    // php: Laravel streams the file itself with Range support (dev / tests).
    'serve_mode' => env('MOVEXA_SERVE_MODE', 'xaccel'),
    'xaccel_prefix' => env('MOVEXA_XACCEL_PREFIX', '/_protected'),

    'signed_url_ttl' => (int) env('MOVEXA_SIGNED_URL_TTL', 4 * 3600),

    'binaries' => [
        'ffmpeg' => env('FFMPEG_BIN', '/usr/bin/ffmpeg'),
        'ffprobe' => env('FFPROBE_BIN', '/usr/bin/ffprobe'),
        'inotifywait' => env('INOTIFYWAIT_BIN', '/usr/bin/inotifywait'),
    ],

    // Sandbox for ffmpeg/ffprobe: none | unshare | bwrap | systemd-run
    'sandbox' => [
        'driver' => env('MOVEXA_SANDBOX', 'systemd-run'),
        'timeout' => (int) env('MOVEXA_SANDBOX_TIMEOUT', 6 * 3600),
        'probe_timeout' => (int) env('MOVEXA_PROBE_TIMEOUT', 60),
        'memory_max' => env('MOVEXA_SANDBOX_MEMORY', '4G'),
        'cpu_quota' => env('MOVEXA_SANDBOX_CPU', '300%'),
        'user' => env('MOVEXA_SANDBOX_USER', 'movexa-sandbox'),
    ],

    'security' => [
        'clamd_socket' => env('CLAMD_SOCKET', '/var/run/clamav/clamd.ctl'),
        'clamd_host' => env('CLAMD_HOST'),           // optional TCP: host:port
        'scanner' => env('MOVEXA_SCANNER', 'clamd'), // clamd | null (tests only)
        'require_scanner' => (bool) env('MOVEXA_REQUIRE_SCANNER', true),
        'quarantine_days' => (int) env('MOVEXA_QUARANTINE_DAYS', 30),
        'allowed_extensions' => [
            'video' => ['mkv', 'mp4', 'm4v', 'avi', 'mov', 'webm', 'ts', 'm2ts', 'mpg', 'mpeg', 'wmv', 'flv'],
            'audio' => ['mp3', 'aac', 'm4a', 'flac', 'ogg', 'opus', 'wav', 'ac3', 'dts'],
            'subtitle' => ['srt', 'vtt', 'ass', 'ssa', 'sub'],
            'image' => ['jpg', 'jpeg', 'png', 'webp'],
        ],
        'duration_tolerance' => 1.5, // seconds
    ],

    'transcode' => [
        'max_concurrent' => (int) env('MOVEXA_MAX_TRANSCODES', 2),
        'hwaccel' => env('MOVEXA_HWACCEL', 'auto'), // auto | vaapi | qsv | nvenc | none
        'vaapi_device' => env('MOVEXA_VAAPI_DEVICE', '/dev/dri/renderD128'),
        'cache_max_gb' => (float) env('MOVEXA_CACHE_MAX_GB', 200),
        'segment_seconds' => 6,
        'ladder' => [
            1080 => ['bitrate' => 5000, 'audio' => 160],
            720 => ['bitrate' => 2800, 'audio' => 128],
            480 => ['bitrate' => 1200, 'audio' => 96],
        ],
    ],

    'live' => [
        'idle_timeout' => (int) env('MOVEXA_LIVE_IDLE', 45), // seconds after last viewer
        'max_restarts' => 5,
        'allow_file_sources' => (bool) env('MOVEXA_LIVE_FILE_SOURCES', false), // test channels only
    ],

    'tmdb' => [
        'key' => env('TMDB_API_KEY'),
        'base' => 'https://api.themoviedb.org/3',
        'image_base' => 'https://image.tmdb.org/t/p/',
        'country' => env('TMDB_CERT_COUNTRY', 'US'),
    ],

    'homecore' => [
        'url' => env('HOMECORE_URL'),
        'oidc' => [
            'enabled' => (bool) env('HOMECORE_OIDC_ENABLED', false),
            'issuer' => env('HOMECORE_OIDC_ISSUER', env('HOMECORE_URL')),
            'client_id' => env('HOMECORE_OIDC_CLIENT_ID'),
            'client_secret' => env('HOMECORE_OIDC_CLIENT_SECRET'),
            'redirect' => env('HOMECORE_OIDC_REDIRECT', env('APP_URL').'/auth/homecore/callback'),
            'scopes' => 'openid profile email',
        ],
        'theme_css' => env('HOMECORE_THEME_CSS'), // URL of HomeCore design tokens CSS
    ],

    'cloudsync' => [
        'url' => env('CLOUDSYNC_URL'),
        'token' => env('CLOUDSYNC_TOKEN'),          // token Movexa uses to call CloudSync
        'root' => env('CLOUDSYNC_ROOT', '/www/data/cloudsync'), // CloudSync storage root on disk
    ],

    'downloads' => [
        'ttl_days' => (int) env('MOVEXA_DOWNLOAD_TTL_DAYS', 30),
    ],

    'discovery' => [
        'top10_days' => (int) env('MOVEXA_TOP10_DAYS', 30),
        'recap_after_days' => (int) env('MOVEXA_RECAP_AFTER_DAYS', 14),
    ],

    'limits' => [
        'streams_per_user' => (int) env('MOVEXA_STREAMS_PER_USER', 3),
        'session_ttl' => 90, // seconds without heartbeat
    ],
];
