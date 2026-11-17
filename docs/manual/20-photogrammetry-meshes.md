# Photogrammetry meshes

Drone surveys and photo scans are often delivered as a **textured mesh**: the ground, stockpiles, buildings and plant as triangles with the photos draped over them. Photogrammetry software (ContextCapture, DJI Terra, Pix4D, Metashape, RealityCapture, OpenDroneMap) exports one as an **OBJ** file with its materials (`.mtl`) and texture images.

Meridian converts a mesh in the library to 3D Tiles, so a twin streams only the detail the camera needs, and places it where the survey says it is.

![A drone survey in the library, converted to 3D Tiles in NZTM2000.](../images/library-mesh.png)

## How it works

```mermaid
flowchart LR
    Up["Upload<br/>.obj, or a zipped export"] --> Q["The conversions queue"]
    Q --> M["meridian-mesh<br/>where is it? reproject to metres<br/>east, north and up of its centre"]
    M --> O["Obj2Tiles<br/>levels of detail, split into tiles"]
    O --> L["The library<br/>3D Tiles, georeferenced or in local metres"]
    L -->|Add| T["A twin"]
```

```text
unpack the zip (refusing paths outside it, links, too many files or too many bytes)
take its largest .obj (from OpenDroneMap, its 3D model)
where is it?
    the EPSG code given at upload, with the offset from metadata.xml or the Pix4D files
    metadata.xml (ContextCapture, DJI Terra): its SRS and SRSOrigin, or ENU:lat,lon
    odm_georeferencing/coords.txt (OpenDroneMap): its UTM zone and offset
    <name>_offset.xyz with <name>.prj (Pix4D)
    otherwise nowhere: the mesh is in local metres
if it's somewhere:
    every vertex, offset and reprojected through Earth-centred coordinates
        to metres east, north and up of the mesh's centre
        (so grid north is turned to true north: about a degree in NZTM)
tile it with Obj2Tiles: 3 levels of detail, each split in a grid,
    the textures packed into each tile
print the triangles, size, tiles and where it is
```

A mesh's heights are taken as they are in the file, as heights above the ellipsoid. If the survey was reduced to a local datum, the mesh may sit a few tens of metres above or below the terrain: move it up or down in the twin's Layout panel.

## Uploading a mesh

1. Open the project, and under **Library** choose the file:
    - an **.obj** on its own, if its materials and textures aren't needed (it's tiled untextured, in a plain colour), or
    - a **.zip** of the whole export folder: the OBJ, its `.mtl`, its texture images, and `metadata.xml`, OpenDroneMap's `odm_georeferencing` folder, or Pix4D's `_offset.xyz` and `.prj` files if there are any.
2. For a zip, set **It is** to **A photogrammetry mesh (an OBJ export)**. For everything OpenDroneMap made, see the next section.
3. If the export doesn't say where it is, and its coordinates are in a projected coordinate system, enter the **EPSG code** (for example `2193` for NZTM2000, or the UTM zone's code). Longitudes and latitudes are refused: export in a projected system.
4. Tick **Y is up** if the mesh is in local metres and was written with Y as the up axis (some scanning apps do). Survey exports are Z-up and don't need it.
5. Choose **Upload**. The library shows **Converting** with progress, and **Mesh · N files · EPSG:…** when it's done, or **local metres** if it wasn't georeferenced. **Log** shows the triangles, size and tiles, and how it was placed.

If it fails, **Log** says why, and **Retry** runs it again, with a new EPSG code if that was the problem.

## An OpenDroneMap export

[OpenDroneMap](https://opendronemap.org) (and WebODM, its web app) turns drone photos into a mesh, an orthophoto, surface and bare-ground models and a point cloud. Upload them all at once:

1. In WebODM, open the task and choose **Download Assets** → **All Assets** (`all.zip`). From OpenDroneMap on the command line, zip the project folder.
2. In the project's **Library**, choose the zip and set **It is** to **An OpenDroneMap export**. Give it a name and the date the photos were taken.
3. Choose **Upload**.

```mermaid
flowchart LR
    Z["all.zip"] --> S["Split on the converter"]
    S --> M["Name<br/>the textured mesh"]
    S --> O["Name: orthophoto<br/>imagery"]
    S --> D["Name: surface model (DSM)<br/>terrain"]
    S --> B["Name: bare ground (DTM)<br/>terrain"]
    S --> P["Name: point cloud"]
```

The upload becomes the **mesh**, and Meridian adds a library item for each of the other outputs it finds, all dated as the upload is. Each converts on its own, with its own **Converting** progress. The mesh is placed where OpenDroneMap's `coords.txt` says; the others carry their own coordinate systems. The upload's log lists what was added.

- OpenDroneMap only makes a bare-ground model (`dtm.tif`) when asked (`--dtm`). Without one, there's no DTM item. For the terrain under a mesh, a DTM suits better than the DSM: see [Terrain](05-terrain.md).
- The report, camera positions, 2.5D mesh and the rest of the export are left out.
- If the export has no textured mesh (`odm_texturing`), the other outputs are still added, and the upload fails saying so. Delete it.

![The library after an OpenDroneMap export: the mesh, orthophoto, surface model and point cloud.](../images/odm-library.png)

## Processing drone photos

Where the server runs [NodeODM](https://github.com/OpenDroneMap/NodeODM) and the organisation has **Photo processing** on (see [Features](02-organisations-and-members.md#features)), Meridian makes the survey from the drone photos itself.

1. Zip the flight's photos (JPEG or TIFF, as the drone took them, with their GPS positions). A `geo.txt` or `gcp_list.txt` beside them is sent too. Anything else, and the junk a Mac adds, is left out.
2. In the project's **Library**, choose the zip and set **It is** to **Drone photos to process**.
3. Choose a **Quality**:
    - **Fast**: a quick look, with a sparser point cloud.
    - **Standard**: OpenDroneMap's defaults.
    - **High**: the most detail, and the slowest.
4. Leave **Bare-ground model (DTM)** ticked for terrain to stand the twin on. Without it, there's only the surface model.
5. Choose **Upload**.

![Uploading a zip of drone photos to process, at Fast quality, with a bare-ground model.](../images/photos-form.png)

```mermaid
flowchart LR
    Z["photos.zip"] --> N["NodeODM<br/>Converting 5–70%"]
    N -->|"all.zip"| S["Split, as an<br/>OpenDroneMap export"]
    S --> M["The mesh<br/>70–100%"]
    S --> O["Orthophoto, DSM,<br/>DTM, point cloud"]
```

The upload shows **Converting** while NodeODM works, from 5% to 70%, then carries on as an [OpenDroneMap export](#an-opendronemap-export): the orthophoto, surface and bare-ground models and point cloud appear beside it, each converting, and the upload becomes the mesh. **Log** says how many photos were sent and how long NodeODM took.

![The photos at 62% while NodeODM works on them.](../images/photos-processing.png)

![Once processed: the surface and bare-ground models, point cloud, orthophoto and mesh, all placed in UTM zone 17N.](../images/photos-library.png)

- **It takes a while.** The 77 photos of OpenDroneMap's Aukerman Park sample, at half size and **Fast**, took NodeODM 32 minutes on 8 cores, then Meridian 7 more to convert the outputs. Meridian only checks on NodeODM every 30 seconds, so other conversions carry on meanwhile.
- **It needs memory.** Those half-size photos processed with 16 GB; the full-size ones didn't. If NodeODM runs out, the upload fails with its last lines. Try **Fast**, or smaller photos, or a bigger NodeODM server.
- **If it fails**, the photos are kept: **Retry** sends them again, with the same quality.
- **Deleting** the upload while NodeODM is working stops it.
- The photos aren't kept once they're processed. Keep your own copy.

## Adding it to a twin

In the twin's **Layout** panel, choose **Add** beside the mesh in the library.

- A **georeferenced** mesh appears where it was surveyed.
- A mesh in **local metres** starts at the twin's origin, and can be placed like a model: moved, turned and raised in the scene or by its numbers. See [Layout and versions](08-layout-and-versions.md).

![The survey in a twin, seen from the south: its red north edge at the back.](../images/mesh-in-twin.png)

The mesh is a tileset like any other: **Uploaded layers** hides it, and measurements and sight lines land on it.

A mesh has its own ground, at the same height as the twin's terrain. So that its ground always shows over the terrain's, instead of the two flickering through each other, it's drawn a hair nearer the camera than it is. Points measured on the mesh are nearer by 0.3% of how far away you're looking from: about 15 cm at 50 m, so zoom in to measure. For the terrain under a survey mesh, a bare-ground model (DTM) suits better than a surface model (DSM): the mesh shows the trees and buildings, and the terrain carries the ground beyond them.

## Limits

| Setting                          | Default         | What it limits                                 |
| -------------------------------- | --------------- | ---------------------------------------------- |
| `MERIDIAN_MESH_MAX_EXTRACTED_MB` | 20480           | The unzipped export, in MB                     |
| `MERIDIAN_MESH_TIMEOUT_MINUTES`  | 240             | How long one conversion may run                |
| `MERIDIAN_MESH_CONVERTER`        | `meridian-mesh` | The converter script, on the worker's `PATH`   |
| `MERIDIAN_NODEODM_URL`           | none            | NodeODM, for processing drone photos           |
| `MERIDIAN_NODEODM_TOKEN`         | none            | NodeODM's token, if it asks for one            |
| `MERIDIAN_PHOTOS_POLL_SECONDS`   | 30              | How often to check on NodeODM                  |
| `MERIDIAN_PHOTOS_TIMEOUT_HOURS`  | 48              | How long NodeODM may take, before it's stopped |

Large surveys take a while: a mesh of tens of millions of triangles can take an hour or more. Big exports can go straight to object storage in parts (see [Large uploads](03-projects-and-the-library.md#large-uploads)).

## About Obj2Tiles

The tiling is done by [Obj2Tiles](https://github.com/OpenDroneMap/Obj2Tiles) 1.9.0 from OpenDroneMap, which is licensed under the **AGPL-3.0**. It's shipped unmodified in the converter image as a separate program in `/opt/obj2tiles`, with its licence, its third-party notices and where to get its source; Meridian only runs it.

## About NodeODM

Photos are processed by [NodeODM](https://github.com/OpenDroneMap/NodeODM) 3.6.2, OpenDroneMap's processing server, which is licensed under the **AGPL-3.0**. It runs unmodified, as its own container from OpenDroneMap's published image (`opendronemap/nodeodm`), and Meridian only talks to it over its web API. It's optional: without it, Meridian has no photo processing, and everything else works the same.

To run it:

- **In development:** `docker compose --profile photos up -d nodeodm`, and set `MERIDIAN_NODEODM_URL=http://nodeodm:3000` in `.env`.
- **In a deployment:** set `MERIDIAN_NODEODM_URL=http://nodeodm:3000` in `docker/production/.env`, and `meridian start` runs it. See [Deploying Meridian](23-deploying.md#photo-processing).
- **Somewhere else:** another NodeODM server, such as a bigger machine, works too. Set its address, and `MERIDIAN_NODEODM_TOKEN` if it asks for a token.

Then owners and admins turn **Photo processing** on under **Features**.

## See also

- [Point clouds](04-point-clouds.md): scans as points rather than a mesh.
- [Comparing surveys](17-comparing-surveys.md): volumes between terrain or point cloud surveys.
- [Projects and the library](03-projects-and-the-library.md): uploads, large uploads and deleting.
