<?php

declare(strict_types=1);

return [

    /*
    | Where uploaded models and tilesets are stored. Use "s3" with RustFS or
    | any S3-compatible store in production.
    */
    'assets_disk' => env('MERIDIAN_ASSETS_DISK', 'local'),

    /*
    | Largest upload accepted, in kilobytes. PHP's own upload_max_filesize and
    | post_max_size must allow at least this much.
    */
    'max_upload_kb' => (int) env('MERIDIAN_MAX_UPLOAD_KB', 512 * 1024),

    'tileset' => [
        'max_files' => (int) env('MERIDIAN_TILESET_MAX_FILES', 20000),
        'max_uncompressed_bytes' => (int) env('MERIDIAN_TILESET_MAX_BYTES', 4 * 1024 * 1024 * 1024),
        'max_root_json_bytes' => 64 * 1024 * 1024,
        'extensions' => ['json', 'b3dm', 'i3dm', 'pnts', 'cmpt', 'glb', 'gltf', 'bin', 'subtree', 'png', 'jpg', 'jpeg', 'webp', 'ktx2'],
    ],

];
