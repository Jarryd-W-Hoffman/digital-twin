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

    /*
    | Uploads straight from the browser to an s3 assets disk, in parts, so
    | large files skip PHP's limits. The bucket needs a CORS rule allowing PUT
    | from the app and exposing ETag. Set the public endpoint when the app
    | reaches the store at an address browsers can't (RustFS inside Docker).
    */
    'direct_uploads' => [
        'enabled' => (bool) env('MERIDIAN_DIRECT_UPLOADS', false),
        'public_endpoint' => env('MERIDIAN_DIRECT_UPLOADS_ENDPOINT'),
        'part_mb' => (int) env('MERIDIAN_DIRECT_UPLOADS_PART_MB', 16),
        'max_upload_gb' => (int) env('MERIDIAN_DIRECT_UPLOADS_MAX_GB', 20),
    ],

    /*
    | Point clouds (LAS, LAZ, E57) are converted to 3D Tiles by a queued job on
    | the "conversions" connection, which runs PDAL and py3dtiles. The Docker
    | converter service has both; elsewhere, install them and set the paths.
    */
    'point_clouds' => [
        'pdal' => env('MERIDIAN_PDAL', 'pdal'),
        'py3dtiles' => env('MERIDIAN_PY3DTILES', 'py3dtiles'),
        'jobs' => (int) env('MERIDIAN_POINT_CLOUD_JOBS', 2),
        'timeout_minutes' => (int) env('MERIDIAN_POINT_CLOUD_TIMEOUT_MINUTES', 240),
    ],

    /*
    | DEMs (GeoTIFF, ASCII grid) are converted to quantized-mesh terrain by the
    | same queue, which runs meridian-terrain (GDAL, pydelatin and
    | quantized-mesh-encoder). The Docker converter service has it.
    */
    'terrain' => [
        'tiler' => env('MERIDIAN_TERRAIN_TILER', 'meridian-terrain'),
        'jobs' => (int) env('MERIDIAN_TERRAIN_JOBS', 2),
        'timeout_minutes' => (int) env('MERIDIAN_TERRAIN_TIMEOUT_MINUTES', 240),
    ],

    /*
    | IFC models are converted to one GLB, with a list of their elements, by
    | meridian-ifc (IfcOpenShell) on the same queue.
    */
    'ifc' => [
        'converter' => env('MERIDIAN_IFC_CONVERTER', 'meridian-ifc'),
        'timeout_minutes' => (int) env('MERIDIAN_IFC_TIMEOUT_MINUTES', 120),
    ],

    /*
    | Readings gateways report (from MQTT or POST /ingest) are kept this long
    | for history charts. TimescaleDB drops older chunks itself; elsewhere the
    | scheduled model:prune does.
    */
    'readings' => [
        'retention_days' => (int) env('MERIDIAN_READINGS_RETENTION_DAYS', 365),
    ],

    /*
    | Devices and gateways log in to the MQTT broker, which asks the control
    | plane through mosquitto-go-auth's HTTP backend. The broker sends the
    | secret in the URLs it calls; without one, those URLs answer 404. The
    | broker URL is shown to people setting up devices.
    */
    'mqtt' => [
        'auth_secret' => env('MERIDIAN_MQTT_AUTH_SECRET'),
        'broker_url' => env('MERIDIAN_MQTT_BROKER_URL', 'mqtt://localhost:1883'),
    ],

    'tileset' => [
        'max_files' => (int) env('MERIDIAN_TILESET_MAX_FILES', 20000),
        'max_uncompressed_bytes' => (int) env('MERIDIAN_TILESET_MAX_BYTES', 4 * 1024 * 1024 * 1024),
        'max_root_json_bytes' => 64 * 1024 * 1024,
        'extensions' => ['json', 'b3dm', 'i3dm', 'pnts', 'cmpt', 'glb', 'gltf', 'bin', 'subtree', 'png', 'jpg', 'jpeg', 'webp', 'ktx2'],
    ],

];
