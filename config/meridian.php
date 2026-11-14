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
    | Photogrammetry meshes (OBJ, or a zipped export with its MTL, textures
    | and georeference) are reprojected by meridian-mesh and tiled by
    | Obj2Tiles on the same queue.
    */
    'mesh' => [
        'converter' => env('MERIDIAN_MESH_CONVERTER', 'meridian-mesh'),
        'max_extracted_mb' => (int) env('MERIDIAN_MESH_MAX_EXTRACTED_MB', 20_480),
        'timeout_minutes' => (int) env('MERIDIAN_MESH_TIMEOUT_MINUTES', 240),
    ],

    /*
    | Map layers (GeoJSON, KML, KMZ, zipped shapefiles, DXF, GeoPackage) are
    | converted to GeoJSON in WGS84 by meridian-vector, and georeferenced images
    | (GeoTIFF orthophotos) to web map tiles by meridian-imagery, both GDAL, on
    | the same queue.
    */
    'vector' => [
        'converter' => env('MERIDIAN_VECTOR_CONVERTER', 'meridian-vector'),
        'max_features' => (int) env('MERIDIAN_VECTOR_MAX_FEATURES', 250_000),
        'timeout_minutes' => (int) env('MERIDIAN_VECTOR_TIMEOUT_MINUTES', 30),
    ],

    'surveys' => [
        'tool' => env('MERIDIAN_VOLUME_TOOL', 'meridian-volume'),
        // The grid a point cloud's heights are kept on, and volumes measured on.
        'cell_m' => (float) env('MERIDIAN_SURVEY_CELL_M', 0.5),
        'max_surveys' => (int) env('MERIDIAN_SURVEY_MAX', 12),
        'timeout_minutes' => (int) env('MERIDIAN_VOLUME_TIMEOUT_MINUTES', 30),
    ],

    'imagery' => [
        'tiler' => env('MERIDIAN_IMAGERY_TILER', 'meridian-imagery'),
        'jobs' => (int) env('MERIDIAN_IMAGERY_JOBS', 2),
        'timeout_minutes' => (int) env('MERIDIAN_IMAGERY_TIMEOUT_MINUTES', 240),
    ],

    /*
    | Readings gateways report (from MQTT or POST /ingest) are kept this long
    | for history charts. TimescaleDB drops older chunks itself; elsewhere the
    | scheduled model:prune does.
    */
    'readings' => [
        'retention_days' => (int) env('MERIDIAN_READINGS_RETENTION_DAYS', 365),
        // CSV files of readings imported into a twin's history.
        'import_max_mb' => (int) env('MERIDIAN_READINGS_IMPORT_MAX_MB', 50),
        'import_max_readings' => (int) env('MERIDIAN_READINGS_IMPORT_MAX', 2_000_000),
    ],

    /*
    | How long an organisation's audit log is kept, in days.
    */
    'audit' => [
        'retention_days' => (int) env('MERIDIAN_AUDIT_RETENTION_DAYS', 730),
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

    /*
    | Alert notifications. Webhooks may not reach private or reserved
    | addresses, or use plain http, unless allow_private_webhooks is on: only
    | for development and tests, where the receiver runs on the same machine.
    */
    'notifications' => [
        'allow_private_webhooks' => (bool) env('MERIDIAN_ALLOW_PRIVATE_WEBHOOKS', false),
        // The queue connection deliveries go on; empty for the default one.
        'connection' => (string) env('MERIDIAN_NOTIFICATIONS_QUEUE', ''),
    ],

    /*
    | Single sign-on through an organisation's OpenID Connect identity
    | provider. Meridian talks to the provider itself, so like webhooks it
    | only reaches public https addresses, unless an identity provider on a
    | private network (an on-premises Keycloak or AD FS) is allowed.
    */
    'sso' => [
        'allow_private_issuers' => (bool) env('MERIDIAN_SSO_ALLOW_PRIVATE_ISSUERS', false),
    ],

];
