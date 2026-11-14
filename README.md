# Meridian

A digital twin platform: a [CesiumJS](https://cesium.com/platform/cesiumjs/) viewer and physics model, plus a Laravel control plane for organisations, projects, versioned twins, and a shared library of uploaded models and 3D Tiles.

A twin is a JSON definition. Meridian turns it into a running physical model, a 3D scene on a real globe, an asset tree, alerting, time travel and what-if forecasts. Teams keep their twins in the control plane: every edit is a version, uploads are checked and served privately, and roles decide who can change a twin and who can only operate or watch it.

![Overview of the Northshore twin](docs/images/overview.png)

**New to Meridian?** The [user manual](docs/manual/README.md) walks through every part of it, step by step, with screenshots: projects and uploads, point clouds, the viewer, alerts and what-if, layout and versions, gateways, devices and MQTT, OPC UA, and running it yourself.

What changed in each version is in the [changelog](CHANGELOG.md).

The sample twin, **Northshore Cold Chain Hub**, is a fictional refrigerated logistics campus in Brisbane. It has two cold stores, three chillers, rooftop and canopy solar, a battery, a diesel generator, electric trucks, yard AGVs and a fire water tank described as a custom asset type. A second sample, **Harbour Depot** in Auckland, is a one-store, grid-only site, there to show that nothing in the platform is specific to the first twin.

## What it does

|                                    |                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| ---------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Organisations and roles**        | Every project and twin belongs to an organisation. Owners and admins edit twins and manage members, operators send commands, viewers watch. People can belong to several organisations and switch between them.                                                                                                                                                                                                                                                                                                            |
| **Versioned twins**                | Each save appends an immutable version: a layout change, an upload, a replaced definition or a restore. Any version can be downloaded as JSON or restored as a new one.                                                                                                                                                                                                                                                                                                                                                    |
| **Projects and uploads**           | Projects group twins with a library of models (`.glb` or `.ifc`), zipped 3D Tiles tilesets and point clouds that any of their twins can show, so one scan serves several twins. Archives are checked entry by entry before anything is written, and files are served only to members of the organisation.                                                                                                                                                                                                                  |
| **Point clouds**                   | LAS, LAZ and E57 scans are converted to 3D Tiles by a queued job (PDAL, then py3dtiles), reprojected onto the globe from their own coordinate system or an EPSG code you give, and optionally thinned. Progress, a log and retries show in the library.                                                                                                                                                                                                                                                                    |
| **Terrain**                        | DEMs (GeoTIFF or ASCII grid) are converted to quantized-mesh terrain by the same queue (GDAL, pydelatin and quantized-mesh-encoder), and a twin can use one as its ground: the site is lifted onto it, zones and routes drape over it, hills hide what's behind them, and it's hillshaded so relief reads up close.                                                                                                                                                                                                        |
| **BIM models**                     | IFC files are converted to one GLB by the same queue (IfcOpenShell), each element a node named by its GlobalId with its name, class and storey. A georeferenced model (IfcMapConversion, or the site's latitude and longitude) is added to a twin where it belongs. An asset can be bound to one element of a model or tileset, which then takes its colour and selects it when clicked.                                                                                                                                   |
| **Placement**                      | Models and local-coordinate tilesets are placed in metres east, north and up from the twin's origin, with heading and scale. Edits preview live in the scene, **Place on map** moves a layer to wherever you click on the ground, and **Move in scene** gives it handles to drag east, north and up, slide and turn.                                                                                                                                                                                                       |
| **Custom asset types**             | Equipment the simulator has no physics for, described as data: metrics with units and defaults, a colour and a shape, plus behaviour written as safe expressions: derived metrics, states and transitions, setpoints operators can change, and power drawn from the feed tree. Each organisation keeps a library of types its twins copy from.                                                                                                                                                                             |
| **Live physics**                   | A microgrid power balance (solar, battery dispatch with peak shaving, grid, generator start delay, load shedding by priority) feeding a refrigeration plant (chiller capacity and COP that derate with ambient air, store thermal mass, door losses, stock spoilage). Vehicles drive their routes and queue for chargers.                                                                                                                                                                                                  |
| **3D scene**                       | The twin opens with a flight down from orbit, and **Globe** pulls back to the whole Earth, lit for the simulated time of day. Assets are extruded from their footprints. Energy and cooling flows animate along arcs, scaled by kW. Alerts raise a beacon over the asset. You can colour by status, temperature or power. A definition's **shapes** draw arrows and azimuth-elevation fans that can follow readings, such as the wind.                                                                                     |
| **Analysis in the scene**          | Built on open CesiumJS, without Cesium ion. **Measure** gives distances (straight, level and vertical), heights above the ground, areas, and a point's position and slope. **Section** cuts the site open above a height or along a vertical cut, clipping models and tilesets. **Sight** tints what a cone, rectangle or dome sensor can see green and what's hidden red, and says whether one point can see another and what's in the way. **Move in scene** drags a placed layer by its handles.                        |
| **Alerting, time travel, what-if** | Threshold, rate-of-change and expression rules with hold times and hysteresis. A 24-hour history to scrub through. Forecasts that fork the current state and run it ahead with and without an event, then apply the event live if you choose. With a gateway, rules run with nobody watching, and every alert is kept in the twin's **alert log** with who acknowledged it, and sent to the project's channels: email, Slack, Teams or a signed webhook, with quiet hours and escalation when nobody acknowledges in time. |
| **Issues in the scene**            | Anyone who can see a twin pins a note or problem to a spot in the scene, with the camera it was seen from, a conversation and photos. Issues are numbered per twin, open from the list, their pin or a link, are assigned to people with due dates, can be raised from an alert on its asset, and reach the project's notification channels.                                                                                                                                                                               |
| **Saved views and links**          | Anyone saves how a twin looks (camera, colouring, layers, section, selection) to come back to, or copies a link that opens it that way. Editors share views with the team and choose the one a twin opens at.                                                                                                                                                                                                                                                                                                              |
| **Share links**                    | Admins and owners share a twin by a link that expires, for someone without an account: the scene from its opening view and, if they choose, live readings and history; nothing can be changed through it, and revoking it stops it at once.                                                                                                                                                                                                                                                                                |
| **Devices and MQTT**               | Devices registered per project, each with its own broker login. The broker asks the control plane about every login and topic, so a device can only publish below its own project's prefix. Topics are mapped onto any asset's metrics, and every reading is kept for charts beyond the live window.                                                                                                                                                                                                                       |
| **Gateway**                        | A Node service that runs the model, streams it over WebSocket, and takes readings from MQTT topics or `POST /ingest`. A reading pins its metric and the model integrates from it, so the twin follows reality where sensors report and fills the gaps where they don't.                                                                                                                                                                                                                                                    |
| **API**                            | Organisation API tokens (shown once, kept as a hash, optionally expiring) let other systems read projects, twins and their assets, readings history, the alert log and issues with their comments and photos as JSON under `/api/v1`, 120 requests a minute per token.                                                                                                                                                                                                                                                     |
| **Audit log**                      | Owners and admins see who did what in the organisation: members and roles, API tokens, projects, uploads, twins and versions, gateways, devices, OPC UA and channels, alerts acknowledged and issues, each with who did it (a person, an API token by name, or Meridian), where, from which address, and the names things had. Kept for two years, and readable through the API.                                                                                                                                           |
| **Single sign-on**                 | Owners connect the organisation's OpenID Connect identity provider (Entra ID, Okta, Google, Keycloak) for its email domains. People sign in through it, newcomers can join with a chosen role, and existing accounts are linked by their owners, never taken over by email. Owners can require it of everyone else, and let it add and remove people automatically (SCIM).                                                                                                                                                 |

![A project: its twins, its library and its devices](docs/images/project.png)

_A project: two twins, a library holding a model, a tileset and a converted laser scan, and the devices allowed to publish readings._

![Placing an uploaded pump skid](docs/images/layout-editor.png)

_The layout editor on Harbour Depot: an uploaded `.glb` placed 38 m west and 30 m south of the origin, turned 30° and doubled in size. The scene follows the numbers as you type; **Save layout** writes them as a new version._

![The twins in an organisation](docs/images/twins.png)

![Members and roles](docs/images/members.png)

![Forecasting a refrigeration board trip](docs/images/what-if.png)

_What-if: if the refrigeration board trips now, the freezer passes its limit in under four hours. The forecast lists every alert that would follow, with cost, diesel, unserved load and stock at risk compared side by side._

![The cascade after applying it](docs/images/cascade.png)

_The same event applied live, coloured by temperature: a red beacon over the tripped board, de-energised chillers, both stores warming, and the alerts stacking up on the timeline._

## Quick start

Needs PHP 8.5 (with `zip`), Composer, and Node 22 or later.

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate --seed
npm install                  # also copies Cesium's static assets to public/vendor/cesium
composer run dev             # Laravel and Vite (Vite on port 5199)
```

Log in at the app URL as `test@example.com` with password `password`; you land on the organisation's projects. The seed creates **Northshore Logistics** with both sample twins, plus `operator@example.com` and `viewer@example.com` (same password) to try the other roles. The sample twins also run without an account at `/demo`.

To try uploads, use `public/samples/models/pump-skid.glb` as a model, and zip `public/tilesets/context/` for a tileset:

```bash
(cd public/tilesets/context && zip -q ../../../context.zip tileset.json context.glb)
```

Point clouds need PDAL and py3dtiles, terrain needs `meridian-terrain` (`docker/converter/meridian-terrain`, with GDAL, pydelatin and quantized-mesh-encoder), IFC models need `meridian-ifc` (`docker/converter/meridian-ifc`, with IfcOpenShell and GDAL), and all need a worker on the conversions queue. The Docker stack's `converter` service has all of them. Without Docker, install them (`pdal` from conda-forge, `pip install "py3dtiles[las]"`) and run:

```bash
php artisan queue:work conversions --queue=conversions --timeout=14400
```

### The gateway

```bash
npm run gateway              # http://127.0.0.1:8787, socket at /ws
# then open /demo?gateway=ws://127.0.0.1:8787/ws
```

Send it a reading, and watch the freezer turn red and the chillers respond:

```bash
curl -X POST http://127.0.0.1:8787/ingest \
  -H 'content-type: application/json' \
  -d '{"asset":"freezer","metric":"tempC","value":-11.5,"ttlS":900}'
```

Custom types take readings the same way, for example `{"asset":"fire-tank","metric":"levelPct","value":48}` raises the _Fire water low_ alert.

### Scripts

| Command                       |                                                                                                                                                                     |
| ----------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `composer run dev`            | Laravel, queue, logs and Vite together                                                                                                                              |
| `composer test`               | Everything CI runs: Pint, Rector, the formatter and linter, PHPStan, `tsc`, 100% type coverage, Pest with 100% code coverage, and Vitest                            |
| `npm run test:twin`           | Vitest only: physics, rules, history, forecasting, validation, gateway                                                                                              |
| `npm run test:e2e`            | Playwright in real Chrome with software WebGL (needs `npm run build` first)                                                                                         |
| `npm run gateway [twin.json]` | The gateway, serving `public/samples/northshore.json` unless you name another                                                                                       |
| `npm run generate:context`    | Rebuilds the sample 3D Tiles tileset                                                                                                                                |
| `npm run generate:model`      | Rebuilds the sample pump skid model                                                                                                                                 |
| `npm run screenshots`         | Regenerates `docs/images` by walking through a seeded app with the real broker and converter (needs `npm run build`, Docker and the `meridian-converter:dev` image) |

## Architecture

```mermaid
flowchart LR
  subgraph browser [Browser]
    P[Inertia pages: projects, twins, members, layout editor]
    VW[Viewer: React panels + TwinScene on Cesium]
    ST[(zustand store)]
    P -->|definition prop| ST
    ST --> VW
  end

  subgraph laravel [Laravel control plane]
    C[Controllers + policies]
    A[Actions: CreateTwin, SaveTwinVersion, StoreProjectAsset, ...]
    VAL[TwinDefinitionValidator]
    DB[(Postgres + TimescaleDB: organisations, projects, twins, versions, devices, readings)]
    DISK[(assets disk: local or S3)]
    C --> A
    A --> VAL
    A --> DB
    A --> DISK
  end

  subgraph workers [Queue workers]
    QW[default: checks direct uploads]
    CV[conversions: PDAL + py3dtiles]
  end

  subgraph core [resources/js/twin/core: no DOM]
    E[engine, rules, history, forecast, validate, telemetry]
  end

  GW[gateway: runner + /ws + POST /ingest]
  MQ[Mosquitto + go-auth]
  DEV[devices] -->|MQTT publish| MQ
  SENS[sensors, SCADA, BMS] -->|POST /ingest| GW
  MQ -->|login and topic checks| C
  MQ -->|subscribed topics| GW
  GW -->|definition, check-ins, readings| C

  P <-->|Inertia visits, forms| C
  VW -->|GET assets/.../files/...| C
  A -.->|jobs| workers
  workers --> DISK
  ST --> E
  GW --> E
  GW <-->|frames, commands| ST
```

The TypeScript in `resources/js/twin/core` has no browser or Cesium dependencies, so the same model runs in the viewer and in the Node gateway. The viewer only sees the `TwinSource` interface, so an in-browser simulator and a remote feed are interchangeable.

The control plane follows [Nuno Maduro's strict starter kit](https://github.com/nunomaduro/laravel-starter-kit-inertia-react): business logic in single-purpose `app/Actions` classes, thin controllers that authorise and delegate, policies for every twin and organisation decision, PHPStan at max, and 100% code and type coverage.

React doesn't render the scene. `TwinScene` is a plain class that owns the Cesium viewer and subscribes to the store directly, so a 4 Hz frame stream never becomes component re-renders. Building bodies are instances in a single Cesium `Primitive`, and a colour change writes straight to that instance's attribute.

### One definition, two validators

The browser validates a definition before running it, and the server validates it before saving it. The two must agree, or the control plane could accept a twin the viewer refuses.

The shape of a definition (which fields, of what type, in what range) lives in one JSON Schema, `resources/js/twin/core/definition.schema.json`, which both sides read: ajv in the browser and gateway, opis/json-schema on the server. Each check carries its message (`x-message`, with `{0:id}` naming the list item and `{value}` the value found), and the same schema tells the definition editor which fields to show and how (`title`, `description`, `x-form`). What needs the whole definition (references to zones, routes, types and assets, duplicates, feed cycles, the main bus, expressions) stays in code: `app/Support/TwinDefinitionValidator.php` mirrors `resources/js/twin/core/validate.ts` message for message. Schema problems come first, ordered by where they are in the definition, then the rest. Both are tested against the same cases in `tests/fixtures/definition-cases.json`:

```text
for each case in definition-cases.json:
    definition ← copy of the base sample, with the case's set / unset / push edits
    expect validateDefinition(definition)        == case.errors     # Vitest
    expect TwinDefinitionValidator→errors(def)   == case.errors     # Pest
```

Every message, and the order they come in, is part of the contract. A change to one validator fails the other's tests until both match.

### Saving a version

```mermaid
sequenceDiagram
  participant U as Owner
  participant E as Layout editor
  participant S as Scene
  participant L as Laravel
  U->>E: East 25, heading 30°
  E->>S: setPlacement(layer, draft)
  Note over S: the model moves at once,<br/>nothing is saved yet
  U->>E: Save layout
  E->>L: POST twins/{twin}/versions {definition}
  L->>L: TwinPolicy::update, ValidTwinDefinition
  L->>L: SaveTwinVersion: lock, number = max + 1, insert
  L-->>E: redirect, props with version n + 1
  E->>S: runDefinition(new version)
```

Versions are never edited. Adding or removing a library upload, replacing the definition and restoring an old version all go through `SaveTwinVersion`, so the history is the complete record of what the twin looked like and who changed it.

### Uploads

```mermaid
flowchart TD
  F[upload] --> X{extension}
  X -->|.glb| G[check the GLB header: magic glTF, version 2, length = file size]
  X -->|.zip| Z[check every entry before writing any]
  G --> W[write to projects/project/assets/asset/]
  Z --> W
  W --> V[ready in the project library; adding it to a twin saves a version]
  V --> R[served by ProjectAssetFileController to members only]
  G -->|fails| E[422 with the reason, nothing kept]
  Z -->|fails| E
```

A tileset zip comes from someone else, so `ExtractTilesetArchive` trusts nothing in it:

```text
for each entry in the zip:
    skip directories, __MACOSX/ and .DS_Store
    refuse if the path is absolute, has a drive letter, a backslash, a NUL,
        or an empty, "." or ".." segment                     # zip slip
    refuse if the entry is a Unix symlink
    refuse if the extension isn't a tile format (json, b3dm, i3dm, pnts, cmpt,
        glb, gltf, bin, subtree, png, jpg, webp, ktx2)
    refuse once the file count or the declared size passes the limits

root ← "tileset.json" at the top, or inside the zip's single top-level folder
refuse unless root parses as JSON with "asset" and "root"

for each entry:
    stream it to the disk, counting real bytes, and stop at the size budget
        # the zip's own size headers can lie; the stream can't

on any failure: delete everything written so far
```

#### Direct uploads

With an `s3` assets disk and `MERIDIAN_DIRECT_UPLOADS=true`, the browser sends files straight to the bucket in parts, so a large scan never passes through PHP or its upload limits:

```mermaid
sequenceDiagram
  participant B as Browser
  participant C as Control plane
  participant S as Bucket
  participant Q as Queue worker
  B->>C: POST projects/{p}/uploads (name, filename, bytes)
  C->>S: CreateMultipartUpload at uploads/{asset}/{file}
  C-->>B: asset id, part size, part count (library item: uploading)
  loop each batch of parts
    B->>C: POST …/parts
    C-->>B: presigned UploadPart URLs (1 hour)
    B->>S: PUT part, 4 at a time, read ETag
  end
  B->>C: POST …/complete (part numbers and ETags)
  C->>S: CompleteMultipartUpload
  C->>Q: ProcessDirectUpload (processing)
  Q->>S: download to a temp file
  Q->>Q: the same GLB and tileset checks
  Q->>S: write files, delete the original (ready, or failed with the reason)
```

Cancelling, or deleting an item still uploading, aborts the multipart upload. The page polls while anything is processing. Part URLs are signed for `MERIDIAN_DIRECT_UPLOADS_ENDPOINT`, since the app reaches RustFS inside Docker at an address browsers can't. The bucket needs a CORS rule allowing `PUT` from the app and exposing `ETag`; `docker/rustfs/create-buckets.sh` sets one. Only ready items can be added to a twin. A processing job that keeps failing (the store is down, say) is retried three times, then marked failed.

#### Point clouds

```mermaid
flowchart TD
  U[LAS, LAZ or E57 upload] --> K[kept as uploaded, at uploads/asset/]
  K --> J[ConvertUpload on the conversions queue]
  J --> S{signature: LASF or ASTM-E57}
  S -->|no| F[failed, with the reason]
  S --> I[pdal info: point count, CRS]
  I --> P[pdal pipeline: assign the EPSG given, thin to a spacing, write LAS]
  P --> T[py3dtiles convert, to ECEF when the CRS is known]
  T --> W[tiles copied into the library; original dropped]
  W --> R[ready: added to twins as a tileset]
  I -->|fails| F
  P -->|fails| F
  T -->|fails| F
  F -->|Retry, maybe with an EPSG code| J
```

![A point cloud converting in the library, with its log](docs/images/library-converting.png)

_A wharf scan at 45%: the log shows each step as the converter reaches it._

![The converted scan placed beside Harbour Depot](docs/images/point-cloud.png)

_The same scan, georeferenced in NZTM (EPSG:2193), landing beside Harbour Depot with no placement of its own._

The job runs only in the `converter` image (`docker/converter`): Alpine's PDAL has no E57 reader and py3dtiles needs glibc, so that image is Debian with PDAL from conda-forge and py3dtiles from PyPI. Conversions get their own queue connection, `conversions`, whose `retry_after` outlasts the four-hour job timeout; it uses the same driver as `QUEUE_CONNECTION`. A failed conversion keeps the original and the last lines from the tool that failed, and is tried once, so the reason is on screen rather than lost in a retry. py3dtiles is always run with `--pyproj-always-xy`: LAS stores easting as X whatever the CRS's axis order, and without it a northing-first system such as NZTM lands on the other side of the planet. A cloud with no coordinate system (common for E57) is tiled in its own metres and starts placed at the twin's origin.

#### Terrain

```mermaid
flowchart TD
  U[GeoTIFF or ASCII grid upload] --> K[kept as uploaded]
  K --> J[ConvertUpload on the conversions queue]
  J --> S{signature: TIFF, BigTIFF or ncols}
  S -->|no| F[failed, with the reason]
  S --> M[meridian-terrain]
  M --> W[tiles copied into the library; original dropped]
  W --> R[ready: a twin's terrain]
  M -->|no CRS, unreadable, only no-data| F
  F -->|Retry, maybe with an EPSG code| J
```

`meridian-terrain` is a short script in the converter image:

```text
reproject the DEM to WGS84 longitude and latitude (GDAL), with overviews
deepest zoom ← where one tile's 256 cells are about as fine as the DEM's pixels
for each zoom, each tile of Cesium's geographic grid over the DEM
(and both whole-hemisphere tiles at zoom 0, so the globe is complete):
    sample 257 × 257 heights over the tile; no data is 0, the ellipsoid
    triangulate to half a cell of error at that zoom (Delatin)
    write {z}/{x}/{y}.terrain with oct-encoded vertex normals
write layer.json: the zooms and tile ranges available
print the CRS, tile count, deepest zoom and height range
```

Heights are used as the DEM gives them, so a DEM in metres above sea level puts the ground a few tens of metres off the ellipsoid; the twin only cares about the height at its origin. The real-tool tests tile a DEM of Auckland with a hill 400 m north of its centre and check the summit lands north, at 100 m: pydelatin counts rows from the south, and getting that wrong flips every tile.

#### BIM models

An `.ifc` upload goes through the same queue to `meridian-ifc`, a short script in the converter image:

```text
check the STEP signature (ISO-10303-21)
triangulate every element with geometry (IfcOpenShell, world coordinates, in metres whatever the file's units)
write model.glb: one node per element, named by its GlobalId, with its name,
    IFC class and storey in the node's extras; a material per surface colour;
    each element also a feature (EXT_mesh_features) with those properties in a
    property table (EXT_structural_metadata), so it can be picked and coloured;
    axes as Cesium reads a glTF: up is Y, and its forward (Z) is east
write elements.json: the same, as a list
where is it?
    IfcMapConversion: eastings and northings reprojected from its CRS to WGS84
        (GDAL), and the rotation of the project's x axis from grid east
    otherwise IfcSite's RefLatitude and RefLongitude, turned by the model
        context's TrueNorth
    otherwise nowhere: the model starts at a twin's origin
a model drawn more than a kilometre from its origin is moved back to it
    (float32 vertices lose millimetres that far out), and the georeference follows
print the schema, element and triangle counts, size and georeference
```

![An IFC shed in the library, converted to six elements](docs/images/library-ifc.png)

Adding a georeferenced model to a twin gives it a placement at that spot, in metres east and north of the twin's origin with the file's heading. One more than 50 km away is probably meant for another site, so it starts at the origin, as an unreferenced one does; either way it's placed like any model. The real-tool tests build a 20 m by 12 m shed in millimetres with `scripts/generate-ifc.py`, once with a map conversion in NZTM and once by its site and true north drawn 5 km off, and check its size, its elements, and where it lands. The heading is from grid north, which differs from true north by the projection's convergence (about a degree across New Zealand in NZTM).

#### The library

Uploads go into the project's library (`project_assets`), and adding one to a twin saves a version with a layer pointing at it. Removing a layer leaves the upload in the library; deleting it from the library takes it out of every twin in the project first. Files are served from `assets/{asset}/files/{path}` after `ProjectPolicy::view` on the upload's project. The URL names neither twin nor project, so layers keep working as twins change. Each response has a fixed content type per extension, `X-Content-Type-Options: nosniff` and `Content-Security-Policy: default-src 'none'; sandbox`, so an uploaded file can never run as a page. The path is checked for `.` and `..` segments too.

### Roles

|                                                                     | Owner | Admin | Operator | Viewer |
| ------------------------------------------------------------------- | :---: | :---: | :------: | :----: |
| See projects and twins, and stream them                             |   ✓   |   ✓   |    ✓     |   ✓    |
| Raise issues in a twin, comment, close and delete their own         |   ✓   |   ✓   |    ✓     |   ✓    |
| Assign their own issues, and close issues assigned to them          |   ✓   |   ✓   |    ✓     |   ✓    |
| Save views of a twin and copy links to them                         |   ✓   |   ✓   |    ✓     |   ✓    |
| Send commands (setpoints, maintenance, scenarios)                   |   ✓   |   ✓   |    ✓     |        |
| Assign, close and reopen anyone's issue                             |   ✓   |   ✓   |    ✓     |        |
| Create projects and twins, upload, place, save and restore versions |   ✓   |   ✓   |          |        |
| Delete anyone's issue or comment                                    |   ✓   |   ✓   |          |        |
| Share views, and choose the view a twin opens at                    |   ✓   |   ✓   |          |        |
| Share a twin by link                                                |   ✓   |   ✓   |          |        |
| Import, save and delete types in the type library                   |   ✓   |   ✓   |          |        |
| Issue gateway tokens, add devices, map telemetry                    |   ✓   |   ✓   |          |        |
| Add members and change roles                                        |   ✓   |   ✓   |          |        |
| Make and revoke API tokens                                          |   ✓   |   ✓   |          |        |
| Read the audit log                                                  |   ✓   |   ✓   |          |        |
| Set up single sign-on                                               |   ✓   |       |          |        |

An organisation always keeps at least one owner: demoting or removing the last one is refused. A viewer's controls are disabled in the inspector, and the store drops their commands too.

### The model, per 5-second step

```text
weather    ← daily sine + slow smooth noise + heatwave fault
             wind: an afternoon breeze from the south-west, gusting on its own noise
custom     ← each custom asset: transitions, then derived metrics, from last step's values
             (an ingested reading pins its metric instead)
demand     ← each store's PI-style call on its chillers, from last step's temperature
desired    ← what every consumer would draw if energised, vehicles asking chargers too
supply     ← solar (irradiance), grid (unless outage), battery (unless locked out),
             generator (once the grid has been gone for startDelayS)

energised  ← walk the feed tree from the main bus, skipping tripped breakers
while desired load > supply:
    shed the next consumer by shedPriority          # admin before refrigeration
if still short: black out

dispatch   ← solar first; battery peak-shaves in the peak tariff window;
             grid or generator covers the rest; surplus charges battery, then exports
             (or is curtailed when islanded)
plant      ← cooling = demand × capacity(ambient); power = cooling / COP(ambient, wear)
stores     ← T += (envelope + door + internal − cooling) × dt / thermal mass
             excursion minutes past the limit put stock at risk after a grace period
roll-up    ← site load, cost by tariff, CO₂, unserved energy
```

Everything the model remembers lives in one flat `values` map of `asset.metric` keys. Noise is a pure function of `(seed, step, channel)`. Together these make the state a plain JSON object that can be cloned, sent over a socket or forked, and a fork with no new faults reproduces the original run exactly. That's what makes the what-if comparison fair: both futures share the same weather and the same noise, so every difference comes from the event.

### Analysis in the scene

The tooling Cesium's commercial ion SDK sells, built on open CesiumJS. Everything runs in the browser for anyone who can see a twin, and nothing is saved except placements (**Save layout**) and a definition's shapes. Each tool's maths lives in `resources/js/twin/core/` without Cesium, so Vitest covers it; the Cesium side is in `resources/js/twin/scene/`.

```mermaid
flowchart LR
    subgraph core["core/ (pure, unit-tested)"]
        measure.ts
        section.ts
        gizmo.ts
        sight.ts
        shapes.ts
    end
    subgraph scene["scene/ (Cesium)"]
        Measure["Measure<br/>pickPosition, globe.pick"]
        Clip["TwinScene.clip<br/>ClippingPlaneCollection per layer"]
        Gizmo["Gizmo<br/>SVG overlay, pointer capture"]
        Sight["Sight<br/>ShadowMap + post-process stage"]
        Shapes["Shapes<br/>arrows, fan meshes"]
    end
    measure.ts --> Measure
    section.ts --> Clip
    gizmo.ts --> Gizmo
    sight.ts --> Sight
    shapes.ts --> Shapes
    Frame["each frame's values"] --> Shapes
```

![A dome viewshed from a 6 m mast in Northshore's yard](docs/images/sight.png)

_A dome viewshed from a 6 m mast in the yard: open ground green; the ground behind the dispatch hall, and the roofs above the mast's eye, red._

**Measure** picks a surface point with `scene.pickPosition` (terrain, blocks, models and scans), falling back to the globe and then the ellipsoid. A **Level** or **Vertical** lock instead finds the point along a line through the first one that's closest to the pointer's ray.

**Section** cuts the scene with Cesium clipping planes:

```text
planes     ← above: a horizontal plane at the cut height
             across: a vertical plane through the cut position, facing the side kept
per layer  ← one ClippingPlaneCollection, updated in place (replacing one mid-draw breaks Cesium)
             unionClippingRegions, so either plane removes what's on its side
             modelMatrix = inverse(layer's own frame) × the site's east-north-up frame
blocks     ← each asset's footprint clipped by the vertical cut (Sutherland–Hodgman),
             its top lowered to the cut height, and rebuilt closed
```

**Sight** draws a viewshed the way a shadow is drawn, with the sensor as the light:

```text
sensor     ← where it was clicked, raised by its height; heading, tilt, range and shape
depth map  ← a Cesium ShadowMap rendered from the sensor (not from the sun):
               cone, rectangle: one perspective map (a spot light) whose frustum holds the shape
               dome:            a cube of six maps (a point light) out to the range
             pushed into each frame's shadow maps by a small primitive
for each pixel on screen (a post-process stage):
    p      ← its position, rebuilt from the scene's depth
    skip unless p is within range and inside the shape
             (cone: within the half angle; rectangle: within both half angles)
    n      ← the surface's normal, from neighbouring pixels' positions
    if n faces away from the sensor: hidden
    else:
        lift p off its surface by a few depth-map texels, more where it's seen edge-on
        hidden if the depth map holds something nearer the sensor along that direction
    tint   ← green if seen, red if hidden, mixed with the scene's own colour
```

While a viewshed shows, the globe casts shadows (so hills hide what's behind them) and tests depth against itself (otherwise Cesium clears the ground's depth before the post-process pass). Both go back to how they were afterwards. A **line of sight** casts a ray from the eye to the target with `scene.pickFromRay`, refines the hit with a second, shorter ray from just before it (the first is read from a depth buffer as long as the scene), and also asks `globe.pick`. The nearest hit short of the target blocks it.

**Move in scene** draws its handles as SVG over the canvas, projected from the layer's frame after every render, so a building can't hide them. Drags turn into metres along the axis, across the level or round the ring (`core/gizmo.ts`), rounded to 0.1 m and whole degrees.

**Shapes** come from the definition. Each frame, a shape's `heading`, `tilt` and `length` expressions are evaluated against that frame's values:

```text
origin     ← its position, or the top of its asset, raised by its height
pose       ← heading mod 360, tilt clamped to ±90°, length at least 0
             (nothing is drawn while a reading it needs is missing)
vector     ← an arrow from origin to origin + length × direction(heading, tilt)
fan        ← a mesh built north-facing and level once (a slice of a disc, or of a shell
             closed by its top, bottom and sides), turned to its pose, rebuilt only when
             the pose changes; see-through and writing no depth, so picks reach what's behind it
```

![Northshore's wind arrow and the yard camera's fan](docs/images/shapes.png)

_Northshore's shapes: the wind arrow on the weather mast, pointing downwind, and the yard camera's fan over the truck apron._

### Gateway protocol

```mermaid
sequenceDiagram
  participant B as Browser
  participant G as Gateway
  participant S as Sensor
  B->>G: WebSocket /ws
  G-->>B: hello {definition, history, live, state}
  loop every 500 ms
    G-->>B: tick {history, live, state, measured}
  end
  B->>G: command {setpoint | maintenance | scenario | clear-faults}
  S->>G: POST /ingest [{asset, metric, value, ttlS}]
  G-->>S: 202 accepted, or 422 with every problem
```

The gateway binds to `127.0.0.1` by default, and browser sockets are accepted only from localhost origins. To expose it:

| Variable          |                                                                  |
| ----------------- | ---------------------------------------------------------------- |
| `GATEWAY_PORT`    | Port, default `8787`                                             |
| `GATEWAY_HOST`    | Bind address, default `127.0.0.1`                                |
| `GATEWAY_TOKEN`   | If set, `POST /ingest` needs `Authorization: Bearer <token>`     |
| `GATEWAY_ORIGINS` | Comma-separated extra browser origins allowed to open the socket |

### Alerts on the gateway

A gateway evaluates the twin's rules on every model step, with the same rule engine as the browser, so alerts are raised while nobody has the twin open. It tells the control plane which alerts are open, not what happened, and the control plane works out the difference:

```text
gateway, after each step     open ← the rule engine's open alerts, one per (rule, asset)
                             if open changed, or 30 s have passed:
                                 POST /api/gateway/alerts {open: [{rule, asset, severity, message, value}]}
                             a refused or failed send is retried after 5 s, still marked changed

control plane, on each POST  in one transaction, with the twin's open alerts locked:
                                 reported, not open here  → raise it (raised_at = now)
                                 open here, not reported  → clear it (cleared_at = now)
                                 both                      → leave it, so it isn't raised twice
```

Sending the whole set, rather than events, keeps the two in step through gateway restarts, lost requests and new versions: an alert still open after a restart is the same alert, and a rule a new version removed clears. The gateway primes its rules on the 8-hour preroll, so hold times and change windows are already filled when it starts. It keeps only open alerts in memory, so it can run for months.

Anyone who can see the twin can read its **alert log** (`/twins/{twin}/alerts`); operators and above acknowledge alerts there or from the scene's Alerts tab while following the gateway, and the first to acknowledge is kept. A twin without a gateway still alerts in the browser, but only while it's open, and nothing is logged.

### Notifications

Each raise and clear the control plane records goes to the project's notification channels, once the change is saved:

```
for each channel of the twin's project:
    skip  if severity below the channel's minimum
    skip  if a clear and the channel doesn't want clears
    quiet if not critical and now (in the channel's time zone) is in its quiet hours
    record (alert, channel, raised|cleared, queued|quiet)
    queued → a delivery job: 3 tries (10 s, 1 min, 5 min), then failed with the reason

escalation channels (told only if nobody acknowledges in time), every minute:
    for each open, unacknowledged alert raised ≥ the channel's wait ago, not yet escalated to it:
        notify (alert, channel, escalated); held by quiet hours → not recorded, tried next minute
    a clear reaches an escalation channel only if the alert was escalated to it

delivery:
    email   → a Markdown mail to each address
    slack   → {"text": …} to hooks.slack.com
    teams   → an Adaptive Card to a Teams workflow URL
    webhook → the alert as JSON, X-Meridian-Signature: sha256=HMAC(body, the channel's secret)
    webhook URLs: https only, resolved once and refused if private or reserved,
                  connected to that same address, redirects not followed

reports (a channel's report: daily | weekly), every five minutes (SendReports):
    due = the last 7:00 in the channel's time zone (weekly: on a Monday)
    skip if the channel was added after due, was sent this one (last_report_at ≥ due), or is in quiet hours
    last_report_at = now; DeliverReport (queued, 3 tries) with BuildProjectReport over [due − a day | week, due):
        per twin: alerts raised, critical, warnings, acknowledged and the median minutes to it,
                  not acknowledged, open now; the 3 assets that raised most;
                  issues raised, closed, open, overdue (the first 5 listed)
    → email: a table per twin; Slack and Teams: a line per twin; webhook: event "report" with the figures
POST /projects/{project}/notification-channels/{channel}/report   send the day or week up to now, at once (editors)
```

![Alert notifications on the project page: the on-call team by email, quiet overnight, and the operations manager for critical alerts only.](docs/images/notification-channels.png)

Editors manage channels on the project page, choosing whether each is told at once or only when nobody acknowledges an alert within a wait, and can **Send test**; webhook URLs and signing secrets are stored encrypted, and the secret is shown once. The alert log lists who was told about each alert and how it went. Deliveries run on the queue (`MERIDIAN_NOTIFICATIONS_QUEUE` picks a connection); email uses Laravel's mailer.

### Issues

An issue is pinned where someone clicked in the scene and keeps the camera they were looking from, so whoever picks it up sees what was meant:

```
POST /twins/{twin}/issues            title, at {lon, lat, height}, view {lon, lat, height, heading, pitch, roll},
                                     and optionally a first comment's body and photo
    lock the twin's row; number = the twin's highest + 1
    the first comment's photo → {assets disk}/projects/{project}/twins/{twin}/issues/{issue}/{uuid7}.{jpg|png|webp}
    → /twins/{twin}?issue={id}, which opens it

GET  /twins/{twin}?issue={id}        the twin page with `issues` (the list, newest activity first) and `issue`
                                     (one, with its comments); the panel reloads only those two props, so the
                                     running twin isn't restarted, and polls them with the gateway every 15 s
POST   …/issues/{issue}/comments     body and/or photo (JPEG, PNG or WebP up to 10 MB); bumps the issue's activity
GET    …/comments/{comment}/photo    the image, with a fixed content type, nosniff and a sandboxing CSP
POST   …/issues/{issue}/closure      close: the first to close it is kept   (its author, its assignee, or an operator)
DELETE …/issues/{issue}/closure      reopen                                  (its author, its assignee, or an operator)
PATCH  …/issues/{issue}/assignment   assignee (a member of the organisation) and due_on, either may be empty
                                                                             (its author, or an operator)
DELETE …/issues/{issue}              with its comments and photos            (its author, or an editor)
DELETE …/comments/{comment}          with its photo                          (its author, or an editor)
```

An issue can follow up an alert, and the project's channels can hear about issues:

```
GET  /twins/{twin}?raise={alert}     `raise`: the alert's id, asset and message, when it's one of the twin's;
                                     the scene flies to the asset and pins the new issue on top of it
POST /twins/{twin}/issues            … alert={id}: must be one of the twin's alerts; kept as issues.alert_id,
                                     and the alert log links each alert to its issues

raised, assigned to someone new, or closed:
    NotifyIssue: each of the project's channels with notify_issues, outside its quiet hours
        → DeliverIssueNotification (queued, 3 tries) → SendChannelMessage, as for alerts,
          with an IssueMessage: "Issue #n raised|assigned to …|closed: <title> (<twin>)"
          and webhook events issue.raised, issue.assigned, issue.closed
    AssignIssue: the new assignee, unless they took it themselves, gets IssueMail (queued)
```

![An issue pinned on Harbour Depot's pump skid, open in the Issues panel with its photo and a reply.](docs/images/issues.png)

In the scene, pins are a Cesium entity each, drawn over everything (`disableDepthTestDistance`), and a click on one opens its issue before anything else is picked. **New issue** turns the next click into a placement, picked on whatever is under the pointer like the measuring tools, and the camera's position and heading, pitch and roll go with it. A link to an issue replaces the opening flight with a flight to its view. Issues belong to the twin, not a version, and are deleted with it.

### Saved views and links

A view is how a twin looks to someone: the camera, the colouring, basemap and layers, any section cut and the selected asset. It's checked against the same limits in the viewer (`twin/core/views.ts`) and the control plane (`App\Support\ViewState`):

```
POST   /twins/{twin}/views                     name, state                       (anyone who can see the twin; private to them)
PATCH  /twins/{twin}/views/{view}              name? state? shared?              (its author, or an editor once shared; only editors share)
DELETE /twins/{twin}/views/{view}                                                (its author, or an editor)
POST   /twins/{twin}/views/{view}/opening      the twin opens here, and the view is shared   (editors)
DELETE /twins/{twin}/opening-view              the twin opens at the whole site again        (editors)

GET /twins/{twin}                      `views`: theirs and the shared ones; `startView`: the twin's opening view
GET /twins/{twin}?view={id}            `startView`: that view, shared or not, if it's one of the twin's
GET /twins/{twin}?look={base64url}     the whole view in the link, read and checked in the browser; nothing saved

state = { camera: {lon, lat, height, heading, pitch, roll},
          colorBy: status | temperature | power, basemap: offline | osm | custom,
          layers: {labels, flows, zones, context, shadows},
          section: {above, across: {offset, heading}} | null, selected: asset id | null }
```

![Harbour Depot's Views panel, with a link to what's on screen, a shared view that opens the twin and one of the user's own.](docs/images/views.png)

Opening a view sets the store's colouring, basemap, layers, section and selection, and asks the scene to fly to its camera, the same request issue links use. A link to an issue wins over a view; a `?look=` link over a saved one; and those over the twin's opening view. Unsharing the opening view, or deleting it, opens the twin at the whole site again. Time isn't kept: the simulator restarts on every visit and a gateway shows the present.

### Share links

Admins and owners show a twin outside the organisation with a link that needs no account. The token is 40 random characters, kept only as SHA-256, shown once; every link expires (1, 7, 30 or 90 days).

```
POST   /twins/{twin}/share-links               name, expires_in_days, live      (admins and owners; audited)
DELETE /twins/{twin}/share-links/{shareLink}                                    (admins and owners; audited)

/share/{token}/…   ResolveShareLink first: unknown, revoked or expired → 404; Referrer-Policy no-referrer, noindex
    GET /                      share/show: latest definition, layer urls rewritten through the link, opening view
    GET /assets/{a}/files/…    only uploads the latest version shows, from the twin's own project
    GET /ticket                live and online: gateway url + ticket {user: share:<id>, operate: false,
                               edit: false, exp: 10 min, close: true}; else {url: null}
    GET /readings              recorded history, when the link is live
    120 requests a minute per address (files: 3000)

gateway: a ticket with close ends its socket at exp (code 4001); the share page's GatewaySource asks
    /ticket for a new url before each reconnect, and stops when there's none (revoked, or no longer live)
```

![Harbour Depot opened through a share link, read only.](docs/images/shared-twin.png)

### The API

Other systems read an organisation's data with one of its API tokens. Admins and owners make and revoke them on the **API** page; a token belongs to the organisation, so it outlives whoever made it:

```
POST   /organisation/api-tokens               name, access (read | write), expires_in_days (30 | 90 | 365 | none)   (admins and owners)
    token = "mrd_" + 48 random characters; keep sha256(token) and its last four; flash the token once
DELETE /organisation/api-tokens/{token}       revoke: it stops working at once                               (admins and owners)

every /api/v1 request, before rate limiting and before route models are found:
    Authorization: Bearer mrd_… → the token with that hash, not expired, else 401
    note last_used_at, at most once a minute; limit 120 requests a minute per token
    a project or twin outside the token's organisation → 404

GET /api/v1/token                                   the token's name, access, expiry and organisation
GET /api/v1/projects[/{project}]                    with twin counts; 50 a page
GET /api/v1/twins[?project=]                        50 a page
GET /api/v1/twins/{twin}                            latest version, assets {id, name, kind, zone}, gateway online, open alerts and issues
GET /api/v1/twins/{twin}/readings                   asset, metric, from, to (ms), buckets: ReadingHistory, as the charts get it
GET /api/v1/twins/{twin}/alerts[?open=1&since=]     newest first, by cursor
GET /api/v1/twins/{twin}/issues[?state=open|closed|all]
GET /api/v1/twins/{twin}/issues/{issue}             with comments; photos at …/comments/{comment}/photo, served as on the web

tokens with write access (any other → 403, before the request is validated):
POST   /api/v1/twins/{twin}/issues                         title, at, view? (default: looking north at the pin), body?, photo?, alert?  → 201
POST   /api/v1/twins/{twin}/issues/{issue}/comments        body and/or photo                                                         → 201
POST   /api/v1/twins/{twin}/issues/{issue}/closure         close (the first to close it is kept); DELETE reopens
POST   /api/v1/twins/{twin}/alerts/{alert}/acknowledgement the first acknowledgement is kept
    through the same actions as the web (CreateIssue, AddIssueComment, CloseIssue, AcknowledgeAlert), which take
    User|ApiToken: a person is kept by id, a token by its name (author_api, closed_by_api, acknowledged_by_api),
    shown as "<name> (API)" and kept after the token is revoked
```

![The API page: a new token shown once with a request to try it, and the organisation's tokens with when each was last used.](docs/images/api-tokens.png)

Answers are Laravel API resources (`App\Http\Resources`), with the content under `data`, and `links` and `meta` on lists. The [manual](docs/manual/15-the-api.md) has examples.

### The audit log

The actions that change an organisation note what they did in its audit log as they do it, so the web, the API, jobs and the seeder are recorded the same way:

```
RecordAuditEvent(action, where: Organisation | Project | Twin, subject?, details?)
    who  = the API token's name, if AuthenticateApiToken put one in the request's hidden Context
           else the signed-in user (kept by id and by name)
           else nobody: shown as "Meridian" (the scheduler, the seeder)
    keep organisation, project and twin by id and by the names they have now, so an entry still
         reads right after they're renamed or deleted (no foreign keys on project_id or twin_id)
    keep the request's IP; never a token, password or secret

AddMember, UpdateMemberRole (from → to), RemoveMember, CreateApiToken (access, expiry), RevokeApiToken,
CreateProject, DeleteProject, StoreProjectAsset / StartDirectUpload, DeleteProjectAsset,
SaveTypeToLibrary, DeleteAssetType, CreateDevice, IssueDevicePassword, DeleteDevice,
CreateOpcUaServer, DeleteOpcUaServer, AllowOpcUaWrites, IssueGatewayToken, RevokeGatewayToken,
CreateNotificationChannel, DeleteNotificationChannel, CreateTwin, UpdateTwin (was), DeleteTwin,
SaveTwinVersion (not a new twin's first), SetOpeningView, AcknowledgeAlert, CreateIssue,
AssignIssue (assignee, due), CloseIssue, ReopenIssue, DeleteIssue
    → RecordAuditEvent, once the change is made (an acknowledgement or close that loses the race records nothing)

GET /organisation/audit[?area=]      admins and owners; newest first, 50 a page by cursor
GET /api/v1/audit[?area=&since=]     any token of the organisation
model:prune (daily)                  drops entries older than MERIDIAN_AUDIT_RETENTION_DAYS (730)
```

![The audit log: who did what in the organisation, newest first, with the project and twin it happened in.](docs/images/audit-log.png)

### Single sign-on

An organisation's owners connect one OpenID Connect identity provider, for the email domains its people use, and can require it of everyone but themselves. Meridian is a confidential client using the authorisation code flow with PKCE, built on Laravel's HTTP client with no extra packages:

```
PUT /organisation/sso      issuer, client_id, client_secret (encrypted; kept when left empty), domains, auto_join, default_role (viewer | operator)
    owners only; fetch {issuer}/.well-known/openid-configuration through WebhookGuard (public https, pinned address)
    and require its "issuer" to match; each domain belongs to one organisation

POST /sso  email                                   (guests, 10 a minute per address)
    domain → its connection → keep {connection, state, nonce, PKCE verifier} in the session
    → the provider's authorization_endpoint: code, openid email profile, state, nonce, S256 challenge, login_hint

GET /sso/callback?code&state                       (20 a minute per address)
    pull the flow from the session; the state must match
    POST token_endpoint: the code, the verifier, client id and secret by HTTP Basic, to the checked address
    take the id_token's claims from that TLS answer (OpenID Connect Core 3.1.3.7, so no signature check)
    and require iss = the issuer, aud ∋ client id, exp, the nonce, a sub, an email (or preferred_username),
    and email_verified not false
    signing in (SignInWithSso):
        an identity with that (connection, sub) → its user, if still a member, else refused
        a user with that email but no identity → refused: they link it themselves
        auto_join and the email in the domains → CreateUser (verified, random password), membership, identity
        else refused
        → log in (Meridian's own 2FA isn't asked), switch to the organisation
    linking (POST /settings/sso, signed in): the same flow, then LinkSsoIdentity for the signed-in user,
        unless that sub is linked to someone else

DELETE /organisation/sso   turns it off; the identities go, the accounts stay

required (owners choose it): policies ask User::accessIn(organisation) rather than roleIn:
    the role, unless the organisation requires single sign-on, they aren't an owner,
    and the session's sso.verified list (set by signing in or linking through it) lacks its connection
    EnsureSsoSignIn sends the organisation's pages to /sso/required, which starts the linking flow
    for the signed-in person and returns to where they were going; owners are exempt as a break-glass

provisioning (SCIM 2.0, /api/scim/v2, Bearer mrs_… made by an owner, kept as SHA-256 on the connection):
    POST /Users          address (primary email, else userName) must be in the connection's domains;
                         an existing account is taken as is, else one is made (no password; its first
                         single sign-on links it, since this provider made it); ScimUser kept per connection
    PUT/PATCH /Users/{id} userName, externalId, name (on accounts it made), active ("True"/"False" too)
    active false / DELETE  RemoveMember, unless an owner (kept, and audited); active true → member again
    GET /Users?filter=userName eq "…" | externalId eq "…", startIndex, count; ServiceProviderConfig
    errors in SCIM form (application/scim+json); 600 requests a minute per connection
```

![Setting up single sign-on: the redirect URI, the provider's issuer and client, the email domains, and newcomers' role.](docs/images/sso-settings.png)

### Running a twin from the control plane

Instead of a JSON file, a gateway can serve a twin saved in the control plane. Open the twin's **Layout** panel, choose **Issue token**, and run the command it shows (the token is shown once; only its hash is stored):

```bash
MERIDIAN_URL=http://localhost:8000 MERIDIAN_GATEWAY_TOKEN=mgw_… npm run gateway
```

The gateway fetches the twin's latest version from `GET /api/gateway/definition`, polls it every few seconds (answered `304` while nothing changed), and restarts the model on each new version. Every poll is also a check-in that reports where browsers can reach it. While it has checked in within the last 30 seconds, the twin page offers a **Simulator / Live** switch and follows the gateway.

![Issuing a gateway token](docs/images/gateway-token.png)

Browsers get in with a ticket the control plane signs for each page view. The HMAC key is the token's SHA-256, which both sides can compute without the control plane holding the token. The ticket names the twin, the user and whether they may operate, so a viewer's socket can watch but not send commands. The same token authorises `POST /ingest`. Rotating or revoking the token cuts the gateway off.

| Variable                            |                                                                     |
| ----------------------------------- | ------------------------------------------------------------------- |
| `MERIDIAN_URL`                      | The control plane, e.g. `http://localhost:8000`                     |
| `MERIDIAN_GATEWAY_TOKEN`            | The twin's gateway token                                            |
| `GATEWAY_PUBLIC_URL`                | Where browsers reach the socket, default `ws://127.0.0.1:<port>/ws` |
| `GATEWAY_POLL_MS`                   | How often to poll for new versions, default `5000`                  |
| `MQTT_URL`                          | A broker to take readings from, e.g. `mqtt://localhost:1883`        |
| `MQTT_USERNAME`, `MQTT_PASSWORD`    | Another broker login; by default `twin-<id>` with the gateway token |
| `OPCUA_PKI_DIR`                     | Where the gateway keeps its OPC UA certificate and trusted servers  |
| `OPCUA_ACCEPT_UNKNOWN_CERTIFICATES` | `1` trusts a server's certificate the first time it's seen          |
| `OPCUA_SERVERS`                     | Without a control plane: servers as JSON, see [OPC UA](#opc-ua)     |
| `OPCUA_WRITES`                      | Without a control plane: `1` writes operators' setpoints to servers |

### MQTT and history

```mermaid
sequenceDiagram
  participant D as Device
  participant M as Mosquitto
  participant L as Laravel
  participant G as Gateway
  participant B as Browsers
  G->>M: CONNECT twin-<id> / gateway token
  M->>L: POST /api/mqtt/<secret>/user
  L-->>M: 200
  G->>M: SUBSCRIBE p/<key>/depot/store/temp
  M->>L: POST /api/mqtt/<secret>/acl (acc 4)
  L-->>M: 200
  D->>M: CONNECT device-<id> / mdv_…
  M->>L: POST /api/mqtt/<secret>/user
  L-->>M: 200, last connected noted
  D->>M: PUBLISH p/<key>/depot/store/temp 1.3
  M->>L: POST /api/mqtt/<secret>/acl (acc 2)
  L-->>M: 200
  M->>G: 1.3
  G->>G: map onto store.tempC, pin it in the model
  G-->>B: tick {measured: [store.tempC]}
  G->>L: POST /api/gateway/readings (every 10 s)
```

Every project has a topic prefix, `p/<key>/`, where the key is the project's first name as a slug plus four random characters (`p/pilot-sites-ayjl/`). It doesn't change when the project is renamed. Each project's MQTT traffic sits below its prefix.

A twin's `telemetry` maps MQTT topics, written relative to that prefix, onto asset metrics. Edit the mappings in the **Telemetry** section of the **Layout** panel; saving makes a new version, and the twin's gateway follows it, subscribing to new topics and dropping old ones.

```json
"telemetry": [
  { "topic": "depot/store/temp", "asset": "store", "metric": "tempC", "ttlS": 900 },
  { "topic": "depot/+/power", "asset": "plant", "metric": "powerKw", "path": "meter.w", "scale": 0.001 }
]
```

Each message, in the gateway:

```text
for each mapping whose topic filter matches (prefix + topic):
    value ← the payload as a number
            or JSON: the number at path (dotted keys), else at "value"
    if no number: count it on /health as unmapped, and skip
    value ← value × scale + offset
    pin asset.metric at value for ttlS seconds      # the model integrates from it
    add {asset, metric, value, at} to the outbox
```

`+` matches one topic level and a final `#` the rest. A payload is a bare number, or JSON with the number at `path` (dotted keys) or at `value`. The value is multiplied by `scale` and then `offset` is added. Mappings onto a metric the asset doesn't have are skipped with a warning, and messages without a number are counted on the gateway's `/health`.

![Mapping a topic onto the Seafood store's temperature](docs/images/telemetry.png)

Every reading, from MQTT or `/ingest`, is also sent to the control plane at `POST /api/gateway/readings` in batches every 10 seconds, authenticated with the gateway's token. While the control plane can't be reached the gateway keeps up to 10,000 readings, dropping the oldest first. The asset's inspector charts what was recorded over the last 24 hours, 7 days or 30 days, in real time rather than the simulated clock.

```text
outbox: up to 10,000 readings, oldest dropped first
every 10 s:
    while the outbox has readings:
        send the oldest 1,000 to POST /api/gateway/readings
        refused or unreachable? keep them all and try again next time
        accepted? drop them from the outbox
control plane: check each against the twin's assets and a recent time window, then insert
```

![Recorded readings in the inspector](docs/images/recorded.png)

_What the store's probe reported over the last day, with the morning's spike, charted from the readings table rather than the model._

Readings are kept for `MERIDIAN_READINGS_RETENTION_DAYS`. In Docker, Postgres runs TimescaleDB: readings become a hypertable in day chunks, compressed after a week, and old chunks are dropped automatically. On plain Postgres or SQLite they stay an ordinary table that the scheduled `model:prune` trims.

### Devices and the broker

Add devices under **Devices** on the project page. Each one gets a broker login, `device-<id>`, and a password that is shown once and can be reset; only its SHA-256 is kept. The page shows when each device last connected.

![A project's devices](docs/images/devices.png)

Mosquitto asks the control plane about every login and topic, through [mosquitto-go-auth](https://github.com/iegomez/mosquitto-go-auth)'s HTTP backend:

```text
login(username, password):
    device-<uuid> → the device exists and sha256(password) matches; note last connected
    twin-<uuid>   → sha256(password) matches the twin's gateway token
    otherwise     → refuse

acl(username, topic, acc):                 # acc: 1 read, 2 write, 4 subscribe
    device → acc is write,            project ← the device's project
    twin   → acc is read or subscribe, project ← the twin's project (while it has a token)
    allow only if topic starts with p/<project key>/ and has something after it
```

- **Devices** may publish below their project's prefix, and nothing else: no subscribing, and no other project's topics.
- **Gateways** log in as `twin-<id>` with the twin's gateway token. They may subscribe to and receive their twin's project topics, and may not publish.
- **Everyone else** is refused, including anonymous clients, and nobody can reach `$SYS`.

The plugin calls `POST /api/mqtt/<secret>/user` and `/acl`. The secret is `MERIDIAN_MQTT_AUTH_SECRET`, which the broker and the app share; without it both routes answer 404. Logins are limited to 30 a minute per username. Each answer is cached for about 30 seconds, so a deleted device or reset password is shut out within a minute. Every check-in also reports whether the gateway is connected to the broker, and the twin's Gateway panel shows it.

The Docker `gateway` profile starts the broker on port 1883. Set the same `MERIDIAN_MQTT_AUTH_SECRET` in `docker/.env` and the app's `.env`, add a device, and publish with the command shown next to its password:

```bash
mosquitto_pub -h localhost -p 1883 -u device-<id> -P mdv_… -t 'p/pilot-sites-ayjl/depot/store/temp' -m -17.5
```

![The twin's gateway, online and connected to the broker](docs/images/gateway-online.png)

The broker speaks plain MQTT. Put it behind TLS (port 8883) before devices connect over networks you don't control.

### OPC UA

![A project's OPC UA servers](docs/images/opcua-servers.png)

Add the project's PLCs and SCADA systems under **OPC UA servers** on the project page: a name, an `opc.tcp://` endpoint, the security mode and policy, and a login if the server wants one. The password is stored encrypted and is only ever sent to the project's gateways. A twin's `opcua` mappings name a server and a node, and feed an asset's metric; edit them in the **OPC UA** section of the **Layout** panel.

```json
"opcua": [
  { "server": "Plant PLC", "node": "ns=2;s=Store.TempC", "asset": "store", "metric": "tempC", "samplingMs": 500, "ttlS": 600 },
  { "server": "Plant PLC", "node": "ns=2;i=1042", "asset": "plant", "metric": "powerKw", "scale": 0.001 }
]
```

The gateway gets the project's servers with each definition (a changed server changes the ETag) and, for each server its twin maps, opens one session with a subscription monitoring every mapped node:

```text
for each server with mapped nodes:
    connect (retrying, backing off to 10 s), log in, subscribe
    monitor each node at the fastest samplingMs asked for it (default 1000)
on a new value with a good status:
    number ← a number, a boolean as 1 or 0, a 64-bit integer, or a numeric string; else skip it
    for each mapping of that node: pin asset.metric at number × scale + offset for ttlS
    add the readings to the outbox, like MQTT's
when the twin or the servers change:
    monitor new nodes, drop old ones, reconnect a server whose settings changed,
    and disconnect from servers no longer mapped or registered
```

![The store's door switch on the Depot PLC, mapped onto Door open](docs/images/opcua-mappings.png)

Editors find node ids by browsing: the **OPC UA** section's browser sends `{"type": "opcua-browse", "id", "server", "node"}` over the gateway socket, and the gateway answers with that node's objects and variables (the Objects folder by default, without namespace 0's own objects), each variable with its type, value and whether its login may write it. A server the twin uses is browsed on its session; any other gets a session of its own, with a 10-second limit, which makes browsing the connection test. Browser tickets carry an `edit` claim, and only those sockets may browse; each may have one browse under way.

![The OPC UA browser on the Depot PLC](docs/images/opcua-browser.png)

Mappings to a server the project doesn't have, or a metric the asset doesn't have, are skipped with a warning. Each check-in reports every server's connection (connecting, connected, reconnecting or failed), shown in the twin's Gateway panel; the gateway's `/health` also has the last error and any nodes the server refused.

#### Writing setpoints

A mapping with `"write": true` also takes an operator's setpoint back to its node. Only a custom type's setpoint metric can be written, and a written mapping can't have a scale of 0. Writing is off for every twin until an editor turns on **Write operators' setpoints to OPC UA** in the twin's OPC UA section; the gateway gets the switch with the definition (it's part of the ETag), and without a control plane it's `OPCUA_WRITES=1`.

```json
{
    "server": "Pump PLC",
    "node": "ns=2;s=Jockey.Start",
    "asset": "jockey",
    "metric": "startKpa",
    "scale": 100,
    "write": true
}
```

```text
on a setpoint command from a socket allowed to operate:
    apply it to the model, as always
    for each written mapping of that asset.metric:
        if writing is off: tell the operator only the model changed, and stop
        setpoint ← the command's value, clamped to the type's min and max
        value ← (setpoint − offset) ÷ scale, so the node reads back as the setpoint
        read the node's DataType; Boolean is value ≠ 0, Float and Double as they are,
            (U)Int8 to (U)Int32 rounded and range-checked; anything else is refused
        write it, and tell the operator if the server refuses
        POST /api/gateway/opcua-writes: who, the node, what was asked for, what was written, and whether it worked
```

The twin's OPC UA section lists the last 20 writes, with who asked and any error. The mapping keeps reading the node too, so the model shows what the PLC actually holds.

For **Sign** and **Sign and encrypt**, the gateway makes its own certificate in `OPCUA_PKI_DIR` on first start. The server has to trust it, and the gateway has to trust the server's: move the server's certificate from `rejected/` to `trusted/certs/` there, or set `OPCUA_ACCEPT_UNKNOWN_CERTIFICATES=1` to trust whatever a server presents first.

`npm run opcua-simulator` starts a test server at `opc.tcp://127.0.0.1:4840/meridian`, with `ns=1;s=Store.TempC` (a slowly swinging freezer temperature), `ns=1;s=Store.DoorOpen`, `ns=1;s=Meter.EnergyWh` and `ns=1;s=Store.Status`, and two writable nodes, `ns=1;s=Pump.StartBar` (a Double) and `ns=1;s=Fan.SpeedPct` (a UInt16). Without a control plane, point a gateway at it with `OPCUA_SERVERS='[{"name": "Plant PLC", "endpoint": "opc.tcp://127.0.0.1:4840/meridian"}]'`; a server there is anonymous and unsecured unless it gives `securityMode`, `securityPolicy`, `username` and `password`.

Reading is all the gateway does for now. Writing setpoints back is the next part of the milestone.

## Writing a twin

Positions are in metres east and north of `origin`. The [Northshore definition](public/samples/northshore.json) uses every feature, and [Harbour Depot](public/samples/harbour-depot.json) is close to the minimum. The smallest twin that runs is what **New twin → An empty site at a location** creates (`app/Support/BlankTwin.php`, pinned in [tests/fixtures/blank-twin.json](tests/fixtures/blank-twin.json)): a site, weather, a grid connection and the one main bus every twin needs.

```jsonc
{
  "id": "harbour-depot",
  "name": "Harbour Depot",
  "origin": { "lon": 174.7855, "lat": -36.8395 },
  "utcOffsetHours": 12,
  "climate": { "meanC": 17, "swingC": 4 },
  "camera": { "headingDeg": 340, "pitchDeg": -35, "rangeM": 220 },
  "tariff": { "offPeakPerKwh": 0.16, "peakPerKwh": 0.34, "peakStartHour": 17, "peakEndHour": 21, ... },
  "zones": [{ "id": "yard", "name": "Depot yard", "footprint": [[-60,-45],[60,-45],[60,45],[-60,45]] }],
  "assets": [
    { "id": "board", "kind": "bus", "footprint": [...], "height": 4 },          // the main bus: no fedBy
    { "id": "plant", "kind": "chiller", "fedBy": "board", "cools": ["store"],
      "params": { "capacityKw": 70, "copNominal": 3.1 } },
    { "id": "store", "kind": "coldstore", "params": { "setpointC": 1, "limitC": 5, "massKwhPerK": 42, ... } }
  ],
  "rules": [
    { "id": "warm", "kind": "coldstore", "metric": "excursionMin", "op": "gt", "threshold": 0.5,
      "severity": "critical", "message": "{asset} is above its temperature limit" }
  ],
  "scenarios": [
    { "id": "trip", "name": "Chiller trips", "faults": [{ "type": "trip", "target": "plant" }] }
  ]
}
```

**Asset kinds:** `site`, `weather`, `grid`, `solar`, `battery`, `genset`, `bus`, `chiller`, `coldstore`, `building`, `charger`, `vehicle` and `custom`. The metrics each built-in kind reports are in [`metrics.ts`](resources/js/twin/core/metrics.ts).

Rules can also compare a metric's change over a window, or raise on an expression; see [Rules](#rules).

**Fault types for scenarios:** `grid-outage`, `trip` (any asset with a target), `door-stuck`, `heatwave`, `bearing-wear`, `genset-fail` and `cloud-cover`. Each can have an `offsetS` and a `durationS`.

### Custom asset types

```jsonc
"types": [{
  "id": "water-tank", "name": "Fire water tank", "primary": "levelPct",
  "metrics": [
    { "key": "levelPct", "label": "Level", "unit": "%", "decimals": 0, "default": 92 },
    { "key": "pressureKpa", "label": "Ring main pressure", "unit": "kPa", "default": 710 }
  ],
  "visual": { "color": "#3a7ca5", "shape": "cylinder", "height": 9 }
}],
"assets": [
  { "id": "fire-tank", "name": "Fire water tank", "kind": "custom", "type": "water-tank",
    "zone": "plant", "position": [134, 70] }
]
```

Type ids are lowercase with `-` or `_`. Metric keys are camelCase like the built-in ones, and `state` is reserved. A custom asset is drawn as its type's shape and colour while it runs, lists its type's metrics in the inspector, and accepts readings for them at `/ingest`.

![A custom asset type in the inspector](docs/images/custom-type.png)

### Behaviour as data

A type can also say how its asset behaves, with no code: metrics worked out from expressions, states it moves between, setpoints operators can change, and power drawn from the bus it's fed by. Northshore's jockey pump keeps the fire main's pressure between two setpoints:

```jsonc
{
    "id": "jockey-pump",
    "name": "Jockey pump",
    "primary": "powerKw",
    "metrics": [
        { "key": "powerKw", "unit": "kW", "expr": "running ? 7.5 : 0" },
        {
            "key": "runMin",
            "unit": "min",
            "expr": "running ? runMin + dt / 60 : 0",
        },
        {
            "key": "startKpa",
            "unit": "kPa",
            "default": 650,
            "setpoint": { "min": 550, "max": 720, "step": 10 },
        },
        {
            "key": "stopKpa",
            "unit": "kPa",
            "default": 740,
            "setpoint": { "min": 680, "max": 820, "step": 10 },
        },
    ],
    "states": [
        { "id": "idle", "label": "Idle", "status": "standby" },
        { "id": "running", "label": "Running", "status": "running" },
    ],
    "transitions": [
        {
            "from": "idle",
            "to": "running",
            "when": "{fire-tank.pressureKpa} < startKpa && powered",
        },
        {
            "from": "running",
            "to": "idle",
            "when": "{fire-tank.pressureKpa} >= stopKpa || !powered",
        },
    ],
    "visual": { "color": "#5b8fb9", "shape": "box", "height": 3 },
}
```

The tank's pressure is an expression too, leaking slowly and rising while the pump runs: `clamp(pressureKpa + ({jockey.powerKw} > 0 ? 12 : -1.5) * dt / 60, 0, 900)`.

Each 5-second step, for every custom asset:

```text
take the first transition whose from is the current state (or *) and whose when holds
for each metric with an expr, in the order listed:
    value ← the expr, reading this step's values so far (its own last value included)
    no value? (it read something missing, or divided by zero) keep the last one
if the asset has fedBy and a powerKw metric: it draws that from the feed tree,
    and is shed, tripped and blacked out like any other load
state colour ← the current state's status (Off whenever its bus is down)
```

| In an expression      |                                                                                                                                                     |
| --------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| Numbers and operators | `+ - * / % ^`, `< <= > >= == !=`, `&& \|\| !` and `c ? a : b`. True is 1 and false is 0; anything non-zero counts as true                           |
| Functions             | `min`, `max`, `abs`, `clamp(x, lo, hi)`, `round`, `floor`, `ceil`, `sqrt`                                                                           |
| Bare names            | The asset's own metrics; its type's state ids (1 in that state, 0 otherwise); `dt` (seconds per step), `hour` (site time), `ambientC` and `powered` |
| `{asset.metric}`      | Another asset's metric, such as `{wx.ambientC}` or `{store.tempC}`                                                                                  |

![The jockey pump in the inspector, with its two pressure setpoints](docs/images/setpoints.png)

Expressions are parsed by hand in both TypeScript and PHP, so nothing in them can reach either language, and both validators report the same problems: syntax, names a type doesn't have, references to assets that don't exist, setpoints without a range, transitions to unknown states. A setpoint metric gets **−**, **+** and **Apply** in the inspector for operators, and the gateway clamps what it receives to the range.

### The type library

Each organisation keeps a library of types under **Types** in the sidebar, so a pump or a meter is described once and used in any of its twins. A fresh install's library starts with Northshore's two.

- **Import** takes a JSON file holding one type, a list of them, or a whole twin definition (its `types` are taken). Every type is checked as a twin would check it, except whether the assets it reads exist, and a type with the same id as one in the library replaces it. The library lists what each type reads, such as `fire-tank`, because a twin needs assets with those ids to use it.
- In a twin's **Layout** panel, **Types** lists the twin's own types against the library. **Add** copies a library type in, and **Take library's** replaces the twin's type of that id. **Save to library** copies the twin's type out. Each copy into a twin saves a version, and the definition must still be valid: the jockey pump can't go into Harbour Depot, which has no `fire-tank`, and the editor says so.
- Copies are not links. Changing or deleting a library type never changes a twin, and gateways keep reading types from the definition alone. The panel marks a twin's type as **in the library** or as **differs from the library**, comparing them field by field.

![The type library](docs/images/types.png)

![A twin's types against the library, with a refusal](docs/images/twin-types.png)

### Rules

Three shapes of rule, each on one `asset` or every asset of a `kind`, with an optional `forS` hold:

```jsonc
"rules": [
  // A metric against a threshold, clearing past clearAt.
  { "id": "freezer-drift", "asset": "freezer", "metric": "tempC", "op": "gt", "threshold": -18,
    "forS": 300, "clearAt": -18.6, "severity": "warning", "message": "{asset} is drifting above -18 °C" },
  // How much a metric changed over a window.
  { "id": "freezer-warming-fast", "asset": "freezer", "metric": "tempC", "change": { "overS": 600 },
    "op": "gt", "threshold": 2, "severity": "warning", "message": "{asset} has warmed 2 °C in ten minutes" },
  // An expression, raised while it holds and cleared when it stops (or once clearWhen holds).
  { "id": "jockey-long-run", "asset": "jockey", "when": "running && runMin > 20",
    "severity": "warning", "message": "{asset} has run for 20 minutes: check the ring main for a leak" }
]
```

A change rule waits until it has a full window of readings. An expression rule reads the asset's metrics by name and others as `{asset.metric}`, and waits while anything it reads has no value.

### KPIs and panels

The top bar and site summary can be declared too. Each figure is an expression over `{asset.metric}` references, with a unit and decimals:

```jsonc
// Replaces the built-in top-bar figures (up to eight).
"kpis": [
  { "label": "Site load", "value": "{site.loadKw}", "unit": "kW" },
  { "label": "Margin to limit", "value": "5 - {store.tempC}", "unit": "°C", "decimals": 1 }
],
// Added to the site summary in the inspector, each with optional figures and a chart.
"panels": [{
  "title": "Fire protection",
  "chart": { "label": "Ring main pressure", "value": "{fire-tank.pressureKpa}", "unit": "kPa" },
  "items": [
    { "label": "Tank level", "value": "{fire-tank.levelPct}", "unit": "%" },
    { "label": "Pressure in hand", "value": "{fire-tank.pressureKpa} - {jockey.startKpa}", "unit": "kPa" }
  ]
}]
```

![Harbour Depot's declared KPIs](docs/images/kpis.png)

![Northshore's Fire protection panel](docs/images/panel.png)

With no `kpis`, the top bar shows site load, solar, grid, battery and the tightest store, as before. A chart is drawn from the history the twin keeps, so it works in replay too. Figures read only `{asset.metric}`, and the validators refuse bare names and references to assets that don't exist.

### Shapes

Arrows and azimuth-elevation fans drawn in the scene. Like KPIs, their `heading`, `tilt` and `length` are expressions over `{asset.metric}`, so a shape can follow readings:

```jsonc
"shapes": [
  { "id": "wind", "name": "Wind", "kind": "vector", "asset": "wx", "height": 2,
    "heading": "{wx.windDeg} + 180", "length": "{wx.windMs} * 6" },
  { "id": "yard-camera", "name": "Yard camera", "kind": "fan", "position": [10, -112], "height": 9.5,
    "heading": "215", "tilt": "-20", "radius": 70, "across": 70, "up": 40 }
]
```

A shape stands at a `position` or on top of an `asset`, raised by `height`. A fan reaches out to `radius` and sweeps `across` degrees side to side and `up` degrees up and down (0 for a flat fan). Weather stations report `windMs` and `windDeg` (where the wind comes from), so `+ 180` points the arrow downwind. See [Shapes](docs/manual/12-twin-definitions.md#shapes) in the manual.

### Terrain

```jsonc
"terrain": { "name": "Site lidar", "url": "/assets/.../files/layer.json", "assetId": "..." }
```

![Harbour Depot on its terrain](docs/images/terrain.png)

Quantized-mesh terrain to stand the site on; without it, the site sits on the plain ellipsoid. The viewer samples the terrain's height at the origin and builds the site level at that height, so pick an origin on the pad the site stands on. Zones and routes drape over the ground, and hills hide what's behind them. Choosing **Use as terrain** on a converted DEM in the twin editor writes this for you; a twin has one terrain, so choosing another replaces it. Harbour Depot uses a synthetic one (`scripts/generate-terrain.py`): a 12 m pad, a ridge to the south-west and the harbour to the north.

### Models and tilesets

```jsonc
"models": [{
  "id": "skid", "name": "Pump skid", "url": "/twins/.../files/model.glb",
  "placement": { "east": -38, "north": -30, "up": 0, "headingDeg": 30, "scale": 2 }
}],
"tilesets": [
  { "id": "context", "name": "Neighbouring buildings", "url": "/tilesets/context/tileset.json" },
  { "id": "scan", "name": "Laser scan", "url": "...", "placement": { ... } }
]
```

A model always has a placement. A tileset without one keeps its own georeference, which is right for most exports (photogrammetry, BIM, point clouds converted with py3dtiles). Give it a placement when it was built in local metres. The control plane writes these entries for you when you upload, with `assetId` linking each one back to its files.

#### Assets as features of a layer

An asset can be one element of a model or tileset, such as the pump in a plant room's IFC model:

```jsonc
"assets": [{ "id": "pump-2", "kind": "custom", "type": "pump", "zone": "plant", "position": [12, 4],
             "feature": { "layer": "plant-room", "id": "2ntlfaHQjB7hg8G8Z3wX3z" } }],
"tilesets": [{ "id": "campus", "url": "...", "featureProperty": "GlobalId" }]
```

The feature takes the asset's colour (status, temperature or power, as its body would), or the selection's while it's selected, and clicking it selects the asset, even through a zone's fill. The asset's own body isn't drawn; its label and position stay. Features are matched by the layer's `featureProperty`, `id` by default, which is what `meridian-ifc` writes, so a converted IFC model binds by GlobalId. A tileset's features need that property in its batch table or metadata. ![The board's wall, selected by clicking it](docs/images/feature-bound.png)

In the definition editor, **Model feature** picks the layer and then the element: from a converted IFC model's element list, typed, or with **Pick in scene**, which takes the next element clicked. Removing a layer from a twin unbinds its assets.

Everything a twin lists can also be edited in the app. **Definition** on the twin page has a tab for each list (zones, assets, routes, types, rules, scenarios, KPIs, panels and shapes). Choosing an item opens a form built from the schema:

- Only the fields that apply are shown, such as a route for a vehicle, a type for a custom asset, or the stores a chiller cools.
- Nested lists, such as a route's stops, a type's metrics, states and transitions, a scenario's faults and a panel's figures, are edited in place.
- Pickers offer what the definition has: zones, routes, chargers, a rule's metrics for its asset, and a type's own metrics and states.
- Footprints and paths are typed one point per line, as `east, north`, or drawn: **Draw in scene** turns clicks on the ground into points (a tenth of a metre apart at finest), and a point can be dragged to move it. **Undo point** and **Start again** help while drawing. A position has **Place in scene**, where a click moves it.

![The definition editor, with the main board bound to the shed's west wall](docs/images/definition-editor.png)

Every change is checked as you type, with the same messages the server gives, and **Save** stays disabled until there are none. Saving writes one version, with a note naming the lists that changed ("Edited types and rules"). **Discard** drops the draft. Strings in a saved definition are kept exactly as sent: Laravel's trimming and empty-to-null middleware skip requests that carry one, so an empty unit stays empty.

The validator reports every problem at once: unknown kinds, zones and types, dangling `fedBy` and `cools` references, feed cycles, a missing main bus, stops off a route, rules without a target, scenarios aimed at assets that don't exist, malformed types, layers with no URL or a bad placement, and assets bound to layers that don't exist.

![Harbour Depot with the placed skid and a converted scan](docs/images/point-cloud.png)

![Pulled back to the whole Earth](docs/images/globe.png)

_Globe view: the site in context, with day and night shading from the simulated clock._

## More views

![Inspecting Chiller 2](docs/images/inspector.png)

_The inspector: live readings with six-hour history, what the asset feeds and is fed by, and the commands it accepts._

![The site at night, coloured by power](docs/images/night-power.png)

_Coloured by power after dark: solar has gone quiet, and the battery and grid carry the plant._

## Configuration

| Variable                                       |                                                                                                                        |
| ---------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| `MERIDIAN_ASSETS_DISK`                         | Filesystem disk for uploads, default `local` (`storage/app/private`). Use `s3` with RustFS or any S3-compatible store. |
| `MERIDIAN_MAX_UPLOAD_KB`                       | Largest upload through PHP, default 512 MB. PHP's `upload_max_filesize` and `post_max_size` must allow it too.         |
| `MERIDIAN_DIRECT_UPLOADS`                      | Upload straight to an `s3` assets disk in parts, default off (on in Docker). Needs a queue worker.                     |
| `MERIDIAN_DIRECT_UPLOADS_ENDPOINT`             | The store's address as browsers see it, when it differs from `AWS_ENDPOINT` (Docker: `http://localhost:9014`)          |
| `MERIDIAN_DIRECT_UPLOADS_PART_MB`              | Part size, default 16 MB (S3 needs at least 5 MB for every part but the last)                                          |
| `MERIDIAN_DIRECT_UPLOADS_MAX_GB`               | Largest direct upload, default 20 GB                                                                                   |
| `MERIDIAN_PDAL`, `MERIDIAN_PY3DTILES`          | The point cloud tools, default `pdal` and `py3dtiles` on the `PATH`                                                    |
| `MERIDIAN_POINT_CLOUD_JOBS`                    | Processes py3dtiles tiles with, default 2                                                                              |
| `MERIDIAN_POINT_CLOUD_TIMEOUT_MINUTES`         | Longest each conversion step may run, default 240                                                                      |
| `MERIDIAN_TERRAIN_TILER`                       | The terrain tiler, default `meridian-terrain` on the `PATH`                                                            |
| `MERIDIAN_TERRAIN_JOBS`                        | Processes it tiles with, default 2                                                                                     |
| `MERIDIAN_TERRAIN_TIMEOUT_MINUTES`             | Longest a terrain conversion may run, default 240                                                                      |
| `MERIDIAN_IFC_CONVERTER`                       | The IFC converter, default `meridian-ifc` on the `PATH`                                                                |
| `MERIDIAN_IFC_TIMEOUT_MINUTES`                 | Longest an IFC conversion may run, default 120                                                                         |
| `MERIDIAN_READINGS_RETENTION_DAYS`             | How long recorded readings are kept, default 365                                                                       |
| `MERIDIAN_AUDIT_RETENTION_DAYS`                | How long the audit log is kept, default 730                                                                            |
| `MERIDIAN_SSO_ALLOW_PRIVATE_ISSUERS`           | `true` to let single sign-on reach an identity provider on a private network (and over `http://`)                      |
| `MERIDIAN_MQTT_AUTH_SECRET`                    | Shared with the MQTT broker, which uses it to ask who may log in and use which topics. Unset, those routes answer 404. |
| `MERIDIAN_MQTT_BROKER_URL`                     | Where devices connect, shown on the project page, default `mqtt://localhost:1883`                                      |
| `MERIDIAN_TILESET_MAX_FILES`                   | Most files in a tileset zip, default 20,000                                                                            |
| `MERIDIAN_TILESET_MAX_BYTES`                   | Largest unpacked tileset, default 4 GB                                                                                 |
| `VITE_BASEMAP_URL`, `VITE_BASEMAP_ATTRIBUTION` | A custom `{z}/{x}/{y}` tile provider and the attribution it requires                                                   |

The default basemap is Natural Earth imagery bundled with Cesium. It makes no third-party requests and works offline. You can switch to OpenStreetMap tiles in the Basemap menu, whose [tile usage policy](https://operations.osmfoundation.org/policies/tiles/) suits development rather than production traffic. Meridian doesn't use Cesium ion, so it needs no access token.

## Tests

- **Pest (100% code and type coverage):** every action, policy, model, rule and controller. Roles at each level, outsiders kept out, the last owner kept, also when an account is deleted (and an organisation with no one else in it deleted with it). Versions number up and restore as new versions. Projects and their libraries: twins created only in the organisation's own projects, uploads added to and removed from twins as versions, and deletions reaching every twin that shows them. Uploads: GLB header checks, tileset extraction, zip slip, symlinks, foreign file types, file count and size limits (including a zip that lies about its sizes), cleanup when anything fails, and file serving headers. Direct uploads against the AWS SDK's mock handler: part URLs signed for the public endpoint, the start, sign, complete and abort lifecycle, uploads no longer open answered with 409, processing in the queue (ready, failed with the reason, retried when the store fails), and only ready uploads reaching twins. Point clouds with PDAL and py3dtiles faked: the commands each step runs, the EPSG and thinning options, signatures, missing and empty files, the tail of a failing tool, the original kept for a retry, and clouds in local metres placed at the origin. DEMs with the tiler faked: TIFF, BigTIFF and ASCII grid signatures, the EPSG option passed on, the tiler's reason kept, a run that writes no `layer.json`, and terrain set, replaced and removed as a twin's ground. IFC models with the converter faked: the signature, the summary in the log, the georeference kept, a run that writes no model, and a model placed by its georeference near the twin, or at the origin when it's far away or says nothing; removing a layer unbinds the assets that were its features. With the real tools, inside the converter image, LAS and E57 scans of Auckland land within 0.01° of where they should, and a DEM of Auckland tiles to zoom 16 with its summit at 100 m, north of centre where it belongs, or asks for an EPSG code when it has no coordinate system, and a shed drawn in millimetres converts to a 20 m GLB of its six elements, each a pickable feature, landing where its map conversion or its site says (`docker run --rm -v "$PWD":/var/www/html -e MERIDIAN_CONVERTER_TESTS=1 meridian-converter php vendor/bin/pest tests/Feature/Conversions`). Readings: gateway batches checked against the twin's assets and a recent time window, bucketed history with lows and highs, other organisations kept out, and pruning by retention. Devices: passwords shown once and reset, names unique per project, and only editors manage them. OPC UA servers: endpoints, security modes and policies that must agree, logins with passwords stored encrypted, names unique per project, only editors manage them, gateways sent their project's servers with a new ETag when one changes, and the statuses a gateway reports kept only when they make sense. OPC UA writes: only editors turn writing on, gateways told with a new ETag, each write recorded with its operator (or none, once they're gone) and its error, and the twin page listing the latest 20. Alerts: a gateway's report raising what's new, keeping what carries on and clearing what's gone, a repeated rule and asset taken once, other twins left alone, bad reports refused; the alert log newest first and a page at a time, open only when asked, kept from outsiders; operators acknowledging (the first kept), viewers refused, and an alert reached only through its own twin. Notifications: channels taking alerts by severity and clears, quiet hours in their own time zone (past midnight too) letting critical alerts through, a record of each send or hold, deliveries retried and failures kept with their reason, email, Slack, Teams and signed webhook payloads, webhooks to private, reserved, loopback or metadata addresses refused (also after a redirect), Slack and Teams kept to their own domains, secrets stored encrypted and shown once, tests that report success or why not, and only editors managing channels. Escalation: alerts left unacknowledged past a channel's wait escalated to it once, not when acknowledged, cleared, too recent, below its severity or in another project, held through quiet hours and sent when they end, a clear reaching only escalation channels that were told, and the check scheduled every minute. Issues: numbered per twin, raised by anyone who can see the twin with a first comment and photo, titles, places, cameras and photo types checked, comments needing words or a photo, closing kept for whoever closed first and reopening, authors closing and deleting their own, operators closing and editors deleting anyone's, outsiders kept out, an issue reached only through its own twin, photos served as images only and deleted with their comment, issue and twin, and the twin page passing the list and the open issue. Assignment: authors and operators giving an issue to a member of the organisation (not an outsider) with a due date, or taking it back, assignees closing it, overdue only while open, a new assignee emailed unless they took it themselves, issues raised from one of the twin's alerts (not another's) and listed under it in the alert log, and channels that take issue updates told of raising, assigning and closing outside their quiet hours, by email, Slack, Teams and signed webhook. Saved views: cameras, colourings, basemaps, layers, sections and selections checked, a section that cuts nothing kept as none, views private to their author until an editor shares them, authors renaming, updating and deleting their own, editors looking after shared ones and choosing the opening view (sharing it, and forgetting it when it's unshared or deleted), a view reached only through its own twin, outsiders kept out, and the twin page passing the views someone can see and the one to open at, from a link or the twin. Share links: made and revoked only by admins and owners, shown once and kept as a hash, unknown, expired and revoked links not found on every route, files loaded only for uploads the twin shows from its own project, no referrer and no indexing, a short read-only ticket that ends its connection only while live and online, history only for live links, and the rate limit; the ticket matches the gateway's check, the gateway ends a share's connection when its ticket runs out, and the viewer asks for a new one before reconnecting. The broker's checks: device and gateway logins, publishing and subscribing only below the project's prefix (not `#`, `p/+/#`, `$SYS` or other projects), revoked tokens and deleted devices shut out, the shared secret, and the per-username login limit. The suite also runs in CI on TimescaleDB Postgres, where readings are a hypertable. The shared definition fixtures, and the shared expression fixtures: the PHP parser refuses exactly what the viewer's does, with the same message. API tokens: made only by admins and owners, shown once and kept as a hash, refused when missing, unknown, revoked or expired (also for ids that don't exist), last use noted once a minute, 120 requests a minute each, and nothing outside their organisation; every API answer, its filters, pages and validation; writes refused to read-only tokens and outside the organisation, issues raised with or without a camera and signed with the token's name after it's revoked, comments with photos, closing and acknowledging keeping the first. Reports: due at 7:00 in each channel's time zone (Mondays for weekly) and sent once, not before a new channel's first, held by quiet hours; the figures per twin (median time to acknowledge, busiest assets, overdue issues), the email, Slack and webhook forms, and Send report now. Audit log: every recorded action with who did it (a person, a token by name, or Meridian), where and the details, names kept after deletion, only admins and owners reading it, areas, pages, the API's `since`, and pruning by retention. Single sign-on: discovery checked and cached, private and plain-http providers refused unless allowed, PKCE, state and nonce, the code swapped as a confidential client, tokens from another issuer or for another client, expired, replayed, without a subject or a verified email refused; sign-ins by subject (also after an email change), removed members turned away, existing accounts never taken over by email, newcomers joining only from the organisation's domains when allowed, linking and unlinking by the account's owner only, provisioning through SCIM (newcomers made and linked on first sign-in, existing accounts taken as they are, deactivation removing members at once but never owners, Entra's string booleans, filters and pages, SCIM errors), requiring it of everyone but owners (pages, actions and direct links refused until the session signs in through it, then back to where they were), only owners setting it up, domains belonging to one organisation, and the rate limits. Browser tests for the kit's account pages.
- **Vitest:** power is conserved every step, including while islanded. Stores hold setpoint for a full day. Outages bridge on battery, start the generator and return to grid. Load sheds by priority. Runs are deterministic, and survive cloning and a JSON round trip. The forecast's baseline matches a straight run exactly. Rule hold times and hysteresis. The gateway's socket, commands, ingest, tokens, body limits and origins. MQTT topic matching and payload mapping, topics placed below the project's prefix, the gateway logging in as its twin and reporting the broker connection, the bridge following new mappings, and the readings outbox holding what the control plane refused. OPC UA against a real in-process server: values of every kind turned into readings, nodes deduplicated at the fastest sampling, monitored nodes following new mappings, refused nodes reported, username logins, a server that isn't there yet retried, servers dropped when they're removed, and each server's status reported at check-in. Writing: setpoints clamped and unscaled for each written mapping, values converted to the node's own type (booleans, floats, and integers rounded and range-checked), writes refused by the server or to servers not connected, only operators' sockets writing and only while the twin allows it, and every write, good or failed, sent to the control plane with who asked. Browsing: one level at a time with types, values and access, namespace 0's own objects left out, a live session reused, bad nodes, wrong logins and missing servers reported, one browse at a time per socket, and only editors' tickets allowed. The shared definition fixtures. Expressions: precedence, short-circuiting, missing values and every parse error, against the same fixtures as PHP. Custom types with behaviour: derived metrics that integrate, transitions, power drawn from a bus and lost with it, setpoints clamped to range, and the jockey pump holding the fire main between its setpoints for six hours. Expression and change rules, including a window that restarts when time goes backwards. Shapes: where a shape stands, a pose that follows readings and wraps past north, and a fan's mesh, flat or with height. Sight: a sensor's look direction and aim, the frustum that holds a cone or rectangle, which directions fall inside each shape, a line of sight judged from what it meets first, and headings as compass points. Moving a layer by its handles: along an axis, up from a side-on view, across the level, turned to whole degrees and wrapped past north, and rays that miss. Sections: footprints clipped by a vertical cut (through corners without doubling them), blocks lowered or taken away, the clipping planes, and the cut's range. Measuring: a distance split into its level and vertical parts, a locked point found along a line, areas and perimeters, slope and the way it faces, and how lengths, areas and angles are written. The gateway watches the rules itself: it sends the open alerts when they change and on a 30-second heartbeat, keeps a change through a failed send and waits before retrying, starts again on a new version, sends one request at a time, and keeps only open alerts in memory.
- **Playwright (real Chrome and WebGL):** an owner logs in, uploads a model, places it, saves, uploads a tileset, reloads and finds both still there. A placed model is dragged east and up, turned and slid by its handles in the scene, and saved. An upload made on the project page is added to a twin and taken out again, staying in the library. A point cloud waits for the converter, shows why its conversion failed, and is retried with an EPSG code. A DEM shows only the EPSG option, converts on the queue, and shows why it failed. In an empty twin, a converted IFC shed is uploaded, the main board is bound to its south wall with **Pick in scene**, the wall takes the board's colour while the board's own box goes, and clicking the wall selects the board. A bad archive is refused with its reason. A type imported into the library is added to Northshore, and the jockey pump is refused by Harbour Depot, which has no fire tank. An owner adds a device, is shown its password and an example command once, resets it, is refused a duplicate name and deletes it. An owner maps an MQTT topic below the project's prefix (a bad filter is refused first) and sees recorded readings charted. An owner registers an OPC UA server (an `http://` endpoint is refused first), then maps one of its nodes to a twin's metric (a bad node id is refused first), and maps the jockey pump's start pressure for writing (a temperature can't be) and turns writing on and off. Through a real gateway and the simulator, an owner is told why a missing server can't be browsed, browses another to its pump setpoint, and maps it with **Map**. An owner renames and describes a twin, then deletes it. A gateway raises the fire tank's alert from a reading with nobody watching, the alert log shows it open, it's acknowledged from the scene's Alerts tab and the log names who did it, and it clears when the level recovers; a webhook channel added on the project page receives a signed test, then the alert's raise and clear, and the log lists it as told; **Raise issue** on the alert opens the twin with a pin on the fire tank and the alert as the title, and the webhook receives the signed `issue.raised`. A viewer pins an issue on Northshore by clicking the scene, with a note and a photo that loads, and comments; the owner opens it from its link, goes back to the list and opens it again by clicking its pin, then closes and reopens it, and assigns it to the viewer with a past due date, so it shows as overdue; the viewer closes it. An owner saves a view of Northshore coloured by temperature with sun shadows on and an asset selected, copies a link to the look with labels off, shares the view and makes the twin open there, and opening it puts everything back; a viewer opening the twin lands on it, can't share or delete it, and the link opens the look with labels off; deleting it opens the twin at the whole site again. A viewer can watch but not edit or operate. On `/demo`: no errors and no third-party requests, canvas picking, commands, forecasts and their cascade, time travel, the globe view, the second twin standing on its terrain's 12 m pad, hillshaded, measuring a 50 m distance, the freezer hall's 18 m height, an 800 m² area and a point's position without selecting anything, a section cutting the blocks and clipping the neighbouring buildings and flipping, a dome, cone and rectangle viewshed tinting the ground in front of the dispatch hall seen and the ground behind it hidden (and the cone leaving it alone when it faces away, then aimed at it), a line of sight blocked by the hall's wall and another across open ground, the wind arrow following the weather mast's readings as the wind shifts and the yard camera's fan drawn, bad files, the gateway path including a real `POST /ingest`, an owner making an API token, reading twins and raising an issue with it (shown in the twin as the token's), and revoking it, a channel's daily report sent at once to the webhook receiver with the day's alert and issue, and phone width.

## Project layout

```text
app/Actions/              business logic: organisations, members, projects, libraries, twins, versions,
                          alerts and their notifications, issues, saved views
app/Jobs/                 queued work: direct uploads, conversions, alert deliveries
app/Http/Controllers/     thin, authorising controllers
app/Policies/             ProjectPolicy, TwinPolicy, OrganisationPolicy, IssuePolicy, IssueCommentPolicy,
                          SavedViewPolicy
app/Support/              TwinDefinitionValidator (mirrors validate.ts), SampleTwins, AlertMessage,
                          WebhookGuard (keeps webhooks off private networks), ViewState (mirrors views.ts)
resources/js/pages/       Inertia pages: twins, members, demo, and the kit's account pages
resources/js/components/  twin-editor (layout, uploads, library, history), twin-issues, twin-views, organisation-switcher
resources/js/twin/core/   model, rules, history, forecast, validation, protocol, and the scene tools'
                          maths: measuring, sections, the gizmo, sight and shapes, and saved views (no DOM)
resources/js/twin/scene/  TwinScene, the Cesium side, and its tools: Measure, Sketch, Gizmo, Sight, Shapes, Pins
resources/js/twin/ui/     the viewer's React panels and charts
gateway/                  the Node gateway
public/samples/           sample twins and the sample model
tests/fixtures/           definition cases shared by Pest and Vitest
tests/e2e/                Playwright specs
```

## Roadmap

Meridian is meant to be a tool teams run for their own sites: bring in scans, models and live data, organise them by project, and build twins from them, all managed from the control plane. The microgrid and cold chain physics stay as a reference domain, and each milestone moves more of what is fixed in code into data people control.

1. **Control plane and gateway** (done): organisations and roles, versioned twins, uploads, placement, custom types, Docker Compose with Postgres and RustFS, and gateways that load their twin from the control plane, follow new versions and admit browsers with signed tickets.
2. **Projects and a data library** (done): organisations hold projects, and projects hold twins and a shared library of uploads (models, tilesets and point clouds), so one scan can be placed into several twins. New twins start as an empty site at a chosen location (weather, a grid connection and a main board), a sample, or a definition file. Large files go straight to object storage in parts and are checked by a queued job.
3. **Point cloud pipeline** (done): LAS, LAZ and E57 uploaded to the library and converted to 3D Tiles by a queued worker (PDAL to read, assign a coordinate system and thin; py3dtiles to reproject and tile), with progress, logs and retries in the UI, then placed into a twin like any tileset.
4. **IoT: devices, MQTT and history** (done): devices registered per project with their own broker logins, a broker that asks the control plane who may publish and subscribe where, an MQTT bridge mapping topics onto any asset's metrics, gateways issued, rotated and watched from the control plane, and TimescaleDB for long-term readings and charts beyond the live window.
5. **Customisation core** (done): behaviour for custom types as data (derived metrics written as safe expressions, state machines, setpoints, power drawn from the feed tree), expression and rate-of-change rules, KPIs and panels declared in the definition, and a type library per organisation that twins copy types from and save them to.
6. **Terrain** (done): DEMs uploaded to the library and converted to quantized-mesh terrain by the conversions worker, which a twin can stand on.
7. **Definition editor** (done): every list in a twin (zones, assets, routes, types, rules, scenarios, KPIs, panels and shapes) is edited in the app, with forms generated from one JSON Schema that also replaced the two hand-kept validators.
8. **Scene editing and BIM** (done): drawing footprints and routes in the scene, IFC import, binding model and tileset features to assets.
9. **OPC UA and actuation** (done): reading from OPC UA servers registered per project, writing back through the custom setpoints (off by default and audited), and browsing a server's nodes through the gateway, which also tests the connection.
10. **Analysis and editing tools in the scene** (done): the kind of tooling Cesium's commercial ion SDK adds, built on open CesiumJS so Meridian still needs neither Cesium ion nor a licence. Measurements (distance between two points with horizontal and vertical locking, height above terrain, polygon area, and a point's latitude, longitude, height and slope); clipping planes to see inside a building or under the ground; a transform gizmo to move, turn and scale layers in place; line of sight and viewsheds from cameras, sensors and drones, with cone, rectangle and dome sensor shapes; and extra shapes such as vectors and azimuth-elevation fans.
11. **Alerts that reach people** (done): a twin's gateway evaluates its rules while nobody has the twin open and keeps an **alert log** in the control plane, with who acknowledged what (part 1, [#32](https://github.com/Jarryd-W-Hoffman/digital-twin/pull/32)). Each project's **notification channels** send alerts by email, Slack, Teams or signed webhook, by severity and with quiet hours (part 2, [#33](https://github.com/Jarryd-W-Hoffman/digital-twin/pull/33)), and **escalate** to further channels when nobody acknowledges an alert in time (part 3, [#34](https://github.com/Jarryd-W-Hoffman/digital-twin/pull/34)). Item 11 is done.
12. **Collaboration in the scene** (done): **issues** pinned to a place in a twin, with the camera they were seen from, comments and photos, opened from a list, their pin or a link, and closed when dealt with (part 1, [#35](https://github.com/Jarryd-W-Hoffman/digital-twin/pull/35)); saved views and links that open a twin at a camera with its colouring, layers, section and selection, shared with the team or chosen as where the twin opens (part 2, [#36](https://github.com/Jarryd-W-Hoffman/digital-twin/pull/36)); and issues assigned to people with due dates, raised from an alert on its asset, with updates sent through the project's notification channels (part 3, [#37](https://github.com/Jarryd-W-Hoffman/digital-twin/pull/37)).
13. **API and reports** (done): organisation API tokens and a versioned read API for projects, twins, readings, alerts and issues (part 1, [#38](https://github.com/Jarryd-W-Hoffman/digital-twin/pull/38)); tokens that may write raise, comment on and close issues and acknowledge alerts, signed with the token's name (part 2, [#39](https://github.com/Jarryd-W-Hoffman/digital-twin/pull/39)); and daily or weekly reports through the notification channels, with each twin's alerts and how fast they were acknowledged, the assets that alerted most, and the issues raised, closed, open and overdue (part 3, [#40](https://github.com/Jarryd-W-Hoffman/digital-twin/pull/40)). Item 13 is done.
14. **Single sign-on and an audit log** (done): an organisation-wide **audit log** of who changed what, for admins and owners and through the API (part 1, [#41](https://github.com/Jarryd-W-Hoffman/digital-twin/pull/41)); and single sign-on through the organisation's OpenID Connect identity provider, with newcomers joining from its domains and existing accounts linked by their owners (part 2, [#42](https://github.com/Jarryd-W-Hoffman/digital-twin/pull/42)).
15. **Requiring single sign-on** (done): owners can require single sign-on of everyone but themselves, so a password alone no longer gets anyone into the organisation ([#43](https://github.com/Jarryd-W-Hoffman/digital-twin/pull/43)).
16. **Automatic provisioning** (done): identity providers add people to the organisation and remove them when they leave, through SCIM 2.0, with a token an owner makes; owners are never removed ([#45](https://github.com/Jarryd-W-Hoffman/digital-twin/pull/45)).
17. **Read-only share links** (done): admins and owners share a twin with a link that expires, for someone without an account, with live readings if they choose and nothing that can be changed; revoking it stops it ([#46](https://github.com/Jarryd-W-Hoffman/digital-twin/pull/46)).
18. **Anomaly detection**: readings that drift from their usual pattern flagged from the recorded history, not only those past a fixed limit, and raised through the existing alerts.
