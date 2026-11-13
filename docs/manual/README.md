# Meridian user manual

Everything Meridian does, one part at a time: what it's for, how it works, and how to use it, step by step. New to Meridian? Start with **Getting started**, then read whichever chapter you need.

![A twin in Meridian: the asset list on the left, the 3D site in the middle, the inspector on the right and the timeline along the bottom.](../images/overview.png)

## Contents

|     | Page                                                             | What's in it                                                                                                                                        |
| --- | ---------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| 🧭  | [Getting started](01-getting-started.md)                         | Signing in, how organisations, projects and twins fit together, finding your way around                                                             |
| 👥  | [Organisations and members](02-organisations-and-members.md)     | Organisations, switching between them, adding people, what each role can do, and the type library                                                   |
| 📁  | [Projects and the library](03-projects-and-the-library.md)       | Projects, uploading models, tilesets and IFC buildings, large uploads, and deleting                                                                 |
| ☁️  | [Point clouds](04-point-clouds.md)                               | Uploading LAS, LAZ and E57 scans, coordinate systems, thinning, progress, logs and retries                                                          |
| 🏔️  | [Terrain](05-terrain.md)                                         | Uploading a DEM, converting it to terrain, and standing a twin on it                                                                                |
| 🌏  | [Twins and the viewer](06-twins-and-the-viewer.md)               | Creating a twin, the scene, measuring, sections, sight lines and viewsheds, issues pinned in the scene, assigned and raised from alerts, saved views and links, the inspector, colours, layers and basemaps |
| ⏱️  | [Alerts, time travel and what-if](07-alerts-time-and-what-if.md) | Alerts, acknowledging them, the alert log and who is told, replaying the last day, forecasting an event before it happens                           |
| 📐  | [Layout and versions](08-layout-and-versions.md)                 | Adding uploads to a twin, placing them or moving them in the scene, terrain, types, saving, downloading and restoring versions                      |
| 📡  | [Gateways and live data](09-gateways-and-live-data.md)           | Running a twin as a service, tokens, the Live switch, and sending readings over HTTP                                                                |
| 🔌  | [Devices and MQTT](10-devices-and-mqtt.md)                       | Registering devices, the broker, mapping topics onto metrics, and the recorded history                                                              |
| 🏭  | [OPC UA](11-opc-ua.md)                                           | Registering PLCs and SCADA servers, browsing them, mapping nodes onto metrics, writing setpoints                                                    |
| 🧾  | [Twin definitions](12-twin-definitions.md)                       | The definition editor, drawing in the scene, binding assets to model elements, shapes, and what's in the JSON                                       |
| 🔐  | [Your account](13-your-account.md)                               | Profile, password, two-factor authentication, passkeys and appearance                                                                               |
| 🛠️  | [Running Meridian](14-running-meridian.md)                       | The Docker stack, workers, the broker, configuration and upgrading                                                                                  |
| 🔑  | [The API](15-the-api.md)                                         | API tokens, reading from other systems, and raising issues and acknowledging alerts from them                                                          |

## Conventions

- **Bold** names a button, menu item, field or heading as it appears in Meridian.
- **Editors** means owners and admins: the roles that can change projects and twins. **Operators** can also send commands to a twin. See [Roles](02-organisations-and-members.md#roles).
- Commands you type in a terminal look like this:

    ```bash
    npm run gateway
    ```

- The examples use the seeded demo: the **Northshore Logistics** organisation, its **Pilot sites** project, and the two sample twins, **Northshore Cold Chain Hub** and **Harbour Depot**.

## About the screenshots

The screenshots are taken by a Playwright walkthrough of a freshly seeded app, using the real MQTT broker, the OPC UA simulator and the converter (for a point cloud, a DEM and an IFC model), so they can be brought up to date whenever the app changes:

```bash
npm run build
npm run screenshots
```

That writes the images into [`docs/images`](../images), which the project README also uses. The walkthrough is [`tests/screenshots/screenshots.spec.ts`](../../tests/screenshots/screenshots.spec.ts); it needs Docker and the converter image (`docker compose -f docker/docker-compose.yml build converter`, tagged `meridian-converter:dev`).
