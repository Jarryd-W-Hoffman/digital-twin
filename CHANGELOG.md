# Changelog

Every notable change to Meridian, newest first. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Meridian is still before 1.0, so:

- a **minor** version (0.x.0) adds a feature, usually one roadmap milestone or part of one, and may change the definition format or the API;
- a **patch** version (0.x.y) fixes, documents or maintains without new features;
- **1.0.0** is kept for when the twin definition format, the gateway API and the upgrade path are declared stable.

Dates are when the change was merged into `main` (UTC).

## [Unreleased]

### Added

- **Definition editor, zones and assets** ([#16]): a **Zones and assets** panel on the twin page, with forms generated from the definition's JSON Schema that show only the fields for an asset's kind. Edits are a draft checked as you type, saved as one version or discarded.
- **Definition editor for every list** ([#18]): **Definition** on the twin page (it was **Zones and assets**) adds routes, types, rules, scenarios, KPIs and panels, with nested forms for a type's metrics, states and transitions, a route's stops, a scenario's faults and a panel's figures. Pickers offer what the definition has, and a save's note names the lists that changed.
- **Drawing in the scene** ([#19]): footprints and paths drawn by clicking the ground, with points that can be dragged, and asset positions placed with a click, from the definition editor.
- **IFC import** ([#20]): `.ifc` uploads are converted on the conversions queue to one GLB (IfcOpenShell, in the converter image), each element a node named by its GlobalId with its name, IFC class and storey. A model georeferenced by IfcMapConversion or its site's latitude and longitude is added to a twin where it belongs.
- **Assets bound to model and tileset features** ([#21]): an asset can be one element of a layer, such as a wall of an IFC model. The element takes the asset's colour and selects it when clicked, and the definition editor picks it from the model's element list or by clicking it in the scene.
- **OPC UA reading** ([#22]): OPC UA servers registered per project (endpoint, security mode and policy, an optional login with the password stored encrypted), and `opcua` mappings in a twin from a server's node to an asset's metric, edited in the **Layout** panel. The gateway subscribes to the mapped nodes with node-opcua, records each new value as a reading, follows changes to the mappings and servers, and reports each server's connection. `npm run opcua-simulator` starts a test server. A new user manual chapter, **OPC UA**, covers it with screenshots; the chapters after it are renumbered.
- **OPC UA write-back** ([#24]): an `opcua` mapping with `write: true` takes an operator's setpoint on a custom type back to its node. The gateway clamps the setpoint to its range, undoes the mapping's scale and offset, converts it to the node's own data type, and writes it. Writing is off for each twin until an editor turns it on, and every write, good or failed, is recorded with who asked and listed in the twin's OPC UA section. The simulator has two writable nodes.
- **Analysis and editing tools** are on the roadmap as item 10 ([#24]).
- **One JSON Schema for a definition's shape** ([#16]), read by ajv in the browser and gateway and by opis/json-schema on the server, each check carrying its message. The hand-kept validators now keep only the checks that need the whole definition.
- Zones are validated: each needs an id and a footprint of 3 or more points ([#16]).
- User manual coverage of the definition editor, drawing in the scene, IFC models and assets bound to model elements, with five new screenshots ([#23]).
- User manual chapter on terrain, and manual and README coverage of custom behaviour, KPIs, panels and the type library, with new screenshots ([#15]).

### Fixed

- The **Definition** panel keeps its tabs and **Save** in view: only the lists and the form scroll, so a long form no longer runs under the save bar ([#23]).
- IFC models converted by `meridian-ifc` no longer land turned 90° in the viewer ([#21]).
- Empty and padded strings in a saved definition are kept as sent, instead of being trimmed or turned into null before validation ([#18]).

### Changed

- The converter image includes IfcOpenShell and `meridian-ifc` ([#20]).
- Validation messages come in a new order: problems with the definition's shape first, sorted by where they are, then references and expressions ([#16]).

## [0.11.0] - 2026-11-07

### Added

- **Type library per organisation** ([#14]): owners and admins import custom types from JSON, everyone in the organisation can browse and download them, and a twin's **Types** section adds a library type, takes the library's version, or saves its own back.
- Library types are checked as a twin would check them, and list the assets they read, since only a twin can say whether those exist.
- Copying a type into a twin saves a version, and is refused, with the reasons, if the twin can't run it.

## [0.10.0] - 2026-11-07

### Added

- **Terrain** ([#13]): GeoTIFF and ASCII grid DEMs uploaded to the library are converted to quantized-mesh terrain on the conversions queue (GDAL, pydelatin, quantized-mesh-encoder), with an optional EPSG code and retries.
- A twin can stand on one terrain: zones and routes drape over the ground, the site level follows the ground height at the origin, and a twin falls back to the plain globe if terrain fails to load.
- Harbour Depot stands on synthetic terrain.

### Changed

- The conversion job, failure and retry are shared by point clouds and terrain.

## [0.9.0] - 2026-11-07

### Added

- **KPIs and panels in the definition** ([#12]): up to eight `kpis` replace the built-in top-bar figures, and `panels` add titled sections with figures and a chart to the site summary, working in replay too.
- Harbour Depot declares its own KPIs, and Northshore gains a fire protection panel.

## [0.8.0] - 2026-11-07

### Added

- **Custom type behaviour as data** ([#10]): derived metrics written as safe expressions (a hand-written parser in TypeScript and PHP, with no `eval`), states and transitions, setpoints with operator controls, and power drawn from the feed tree.
- **Richer rules:** rate-of-change rules (`change.overS`) and expression rules (`when`, `clearWhen`).
- A jockey pump on Northshore's fire main, with live setpoints and a run-time rule.

## [0.7.2] - 2026-11-07

### Fixed

- `npm install` in the Docker `php` container failing with `EACCES` on the `node_modules` volume ([#11]).

## [0.7.1] - 2026-11-07

### Added

- A user manual in `docs/manual/` ([#9]).
- README architecture and MQTT diagrams, pseudocode for the broker checks and telemetry, and new screenshots.
- `npm run screenshots`: a Playwright walkthrough that regenerates every image from a freshly seeded app.

## [0.7.0] - 2026-11-07

### Added

- **Devices** ([#8]): devices registered per project, each with its own broker login whose password is shown once.
- **Broker authentication:** Mosquitto asks the control plane who may log in, publish and subscribe. Devices publish only below their project's topic prefix, and gateways read only their own project's topics.
- Each project has a fixed topic prefix (`p/<key>/`), and telemetry mappings are written relative to it.
- A gateway reports whether it's connected to the broker.

## [0.6.0] - 2026-11-07

### Added

- **MQTT telemetry** ([#7]): the definition maps MQTT topics onto asset metrics, edited in the **Telemetry** section of the Layout panel, and the gateway subscribes to them and feeds the model.
- **Reading history:** gateways batch every reading to the control plane, and the inspector charts recorded readings over 24 hours, 7 days or 30 days.
- Optional TimescaleDB, with a hypertable, compression and retention.

### Fixed

- Three queries that SQLite accepted but Postgres rejected, which broke saving versions and the last-owner check.

## [0.5.0] - 2026-11-07

### Added

- **Point cloud pipeline** ([#6]): LAS, LAZ and E57 scans uploaded to the library are converted to 3D Tiles by a queued worker (PDAL, then py3dtiles), with progress, logs, failure reasons, an EPSG code and thinning, then placed into twins like any tileset.
- A converter Docker image, and CI that runs real conversions when it changes.

## [0.4.0] - 2026-11-07

### Added

- **Direct uploads** ([#5]): large library files go from the browser straight to object storage in parts, then a queued job checks them. Library items show uploading, processing, ready and failed states.

## [0.3.0] - 2026-11-07

### Added

- **Empty twins** ([#4]): a new twin can start as an empty site at a latitude and longitude, with weather, a grid connection and a main board, as well as from a sample or a definition file.

### Changed

- CI uses `voidzero-dev/setup-vp` 1.22.0 ([#1]).

## [0.2.0] - 2026-11-07

### Added

- **Projects and a shared library** ([#3]): organisations hold projects, and projects hold twins and a library of uploads that several twins can use. Adding an upload to a twin, or removing it, saves a version.

### Changed

- Existing twins move into a "General" project per organisation, their uploads into its library, and saved layer URLs are rewritten.

## [0.1.0] - 2026-11-06

### Added

- **Docker stack** ([#2]): Postgres, Redis, Mailpit, RustFS, php-fpm, nginx, the queue and scheduler, and gateway and Vite services.
- **Gateways run from the control plane:** a twin issues gateway tokens; the gateway loads the twin's latest version, follows new ones, and admits browsers with tickets the control plane signs, so watch-only viewers can't send commands.
- A **Simulator / Live** switch on the twin page while its gateway is online.

## [0.0.2] - 2026-11-06

### Changed

- README rewritten for the control plane, screenshots recaptured, and CI moved to npm.

## [0.0.1] - 2026-11-06

### Added

- The Meridian digital twin viewer: a twin defined in JSON, run as a physical model (a microgrid and a refrigeration plant, vehicles on routes) and shown on a CesiumJS globe, with an asset tree and inspector, threshold alerts, a 24-hour time travel, and what-if forecasts.
- A gateway that streams the model over WebSocket and accepts sensor readings.
- The control plane, on a Laravel starter kit: accounts with passkeys and two-factor authentication, organisations with roles, versioned twins with restore, model and tileset uploads with placement, and the Northshore and Harbour Depot samples.
- Matching definition validators in TypeScript and PHP, held to shared fixtures.

[Unreleased]: https://github.com/Jarryd-W-Hoffman/digital-twin/compare/ea36e22...HEAD
[0.11.0]: https://github.com/Jarryd-W-Hoffman/digital-twin/compare/7971efe...ea36e22
[0.10.0]: https://github.com/Jarryd-W-Hoffman/digital-twin/compare/a4505f3...7971efe
[0.9.0]: https://github.com/Jarryd-W-Hoffman/digital-twin/compare/0232b94...a4505f3
[0.8.0]: https://github.com/Jarryd-W-Hoffman/digital-twin/compare/c701c68...0232b94
[0.7.2]: https://github.com/Jarryd-W-Hoffman/digital-twin/compare/5b22a35...c701c68
[0.7.1]: https://github.com/Jarryd-W-Hoffman/digital-twin/compare/f3dc30e...5b22a35
[0.7.0]: https://github.com/Jarryd-W-Hoffman/digital-twin/compare/f503e5f...f3dc30e
[0.6.0]: https://github.com/Jarryd-W-Hoffman/digital-twin/compare/3bca544...f503e5f
[0.5.0]: https://github.com/Jarryd-W-Hoffman/digital-twin/compare/d8ff267...3bca544
[0.4.0]: https://github.com/Jarryd-W-Hoffman/digital-twin/compare/67cd5ee...d8ff267
[0.3.0]: https://github.com/Jarryd-W-Hoffman/digital-twin/compare/f4cad92...67cd5ee
[0.2.0]: https://github.com/Jarryd-W-Hoffman/digital-twin/compare/c5994e3...f4cad92
[0.1.0]: https://github.com/Jarryd-W-Hoffman/digital-twin/compare/d40e9ef...c5994e3
[0.0.2]: https://github.com/Jarryd-W-Hoffman/digital-twin/compare/1de3a66...d40e9ef
[0.0.1]: https://github.com/Jarryd-W-Hoffman/digital-twin/commit/1de3a66
[#1]: https://github.com/Jarryd-W-Hoffman/digital-twin/pull/1
[#2]: https://github.com/Jarryd-W-Hoffman/digital-twin/pull/2
[#3]: https://github.com/Jarryd-W-Hoffman/digital-twin/pull/3
[#4]: https://github.com/Jarryd-W-Hoffman/digital-twin/pull/4
[#5]: https://github.com/Jarryd-W-Hoffman/digital-twin/pull/5
[#6]: https://github.com/Jarryd-W-Hoffman/digital-twin/pull/6
[#7]: https://github.com/Jarryd-W-Hoffman/digital-twin/pull/7
[#8]: https://github.com/Jarryd-W-Hoffman/digital-twin/pull/8
[#9]: https://github.com/Jarryd-W-Hoffman/digital-twin/pull/9
[#10]: https://github.com/Jarryd-W-Hoffman/digital-twin/pull/10
[#11]: https://github.com/Jarryd-W-Hoffman/digital-twin/pull/11
[#12]: https://github.com/Jarryd-W-Hoffman/digital-twin/pull/12
[#13]: https://github.com/Jarryd-W-Hoffman/digital-twin/pull/13
[#14]: https://github.com/Jarryd-W-Hoffman/digital-twin/pull/14
[#15]: https://github.com/Jarryd-W-Hoffman/digital-twin/pull/15
[#16]: https://github.com/Jarryd-W-Hoffman/digital-twin/pull/16
[#18]: https://github.com/Jarryd-W-Hoffman/digital-twin/pull/18
[#19]: https://github.com/Jarryd-W-Hoffman/digital-twin/pull/19
[#20]: https://github.com/Jarryd-W-Hoffman/digital-twin/pull/20
[#21]: https://github.com/Jarryd-W-Hoffman/digital-twin/pull/21
[#22]: https://github.com/Jarryd-W-Hoffman/digital-twin/pull/22
[#23]: https://github.com/Jarryd-W-Hoffman/digital-twin/pull/23
[#24]: https://github.com/Jarryd-W-Hoffman/digital-twin/pull/24
