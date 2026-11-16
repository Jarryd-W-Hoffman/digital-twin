# Terrain

Without terrain, a twin sits on the smooth globe: fine for a flat yard, odd for a site on a hillside or beside a harbour. Upload a **digital elevation model** (DEM), the height grid a surveyor or a national lidar programme gives you, and Meridian turns it into terrain the twin can stand on. Hills rise around the site, zones and routes drape over the ground, and anything behind a ridge is hidden by it.

![Harbour Depot standing on its terrain, seen low from the north-east: the depot on its 12 m pad, with the ridge to the south-west rising on the skyline behind it.](../images/terrain.png)

## How it works

```mermaid
flowchart LR
    U["Your DEM<br/>.tif .tiff .asc"] --> Q["Waits for the converter"]
    Q --> R["Read it<br/>(heights, coordinate system)"]
    R --> W["Reproject onto the globe"]
    W --> G["Fill its gaps<br/>(under trees, buildings)"]
    G --> T["Cut into tiles<br/>(finer near the ground)"]
    T --> L["Ready in the library"]
    R -->|can't| F["Failed, with the reason"]
    F -->|Retry| Q
```

- The converter that turns [point clouds](04-point-clouds.md) into tiles also does terrain. Each zoom level is a mesh that keeps the ground's shape to within a fraction of the DEM's cell size, so flat ground costs almost nothing and detail goes where the ground changes.
- Heights are used as the DEM gives them, usually metres above sea level.
- **Gaps** in the DEM, such as under trees in a bare-ground model, or around buildings and water, are filled from the ground around them, so the terrain has no holes or spikes.
- **Beyond the DEM**, the ground stays level at the height of the DEM's edges, so a site high above sea level stands on level ground instead of a cliff down to sea level.
- The filled gaps and the level ground beyond are only for showing the site. Measurements and volumes use the DEM's own heights, gaps and all.
- The DEM's heights are also kept as they were, beside the tiles, so it can be [compared with other surveys](17-comparing-surveys.md) of the same ground.
- A twin can have **several** terrains, such as a survey of the same ground each month. It stands on one at a time: the newest captured by the twin's **Site on** date (see [The site over time](21-the-site-over-time.md)).
- Terrain is **hillshaded**: lit from the north-west, as on a map, so slopes facing north-west are lighter and those facing south-east darker, whatever the time of day. Flat ground keeps its colour.

## Upload a DEM

1. On the project page, under **Library**, choose a `.tif`, `.tiff` (GeoTIFF) or `.asc` (ASCII grid) file. For a GeoTIFF, leave **It is** on **Terrain (an elevation model)**.
2. **EPSG code** is the only extra field. Leave it empty if the file has a coordinate system, as GeoTIFFs usually do. An ASCII grid usually doesn't, so enter its code, such as `2193` for New Zealand Transverse Mercator.
3. Choose **Upload**.

The item shows **Converting** while it works. Open **Log** to see what was made:

```text
Downloaded depot-ground.tif
EPSG:2193, 43 tiles to zoom 14, heights 0 to 96.77 m
45 files written
```

When it's done, the item reads **Terrain · 45 files · EPSG:2193**.

![A DEM in the library, converted to terrain.](../images/library-terrain.png)

## When a conversion fails

| Reason                                                          | What to do                                                      |
| --------------------------------------------------------------- | --------------------------------------------------------------- |
| **That is not a GeoTIFF or ASCII grid elevation model.**        | Export the DEM again as a GeoTIFF.                              |
| **The DEM has no coordinate system. Retry with its EPSG code.** | Enter the code in the box under the item and choose **Retry**.  |
| **The DEM has no heights, only no-data.**                       | The export covers the wrong area, or every cell was masked out. |
| **The file has no elevation band.**                             | The file is an image, not a height grid.                        |

## Stand a twin on it

1. Open a twin in the same project and choose **Layout**.
2. Under **Library**, choose **Use as terrain** beside the DEM (**Add as another terrain** if the twin already has one). The twin saves a new version, **Set the terrain to…** or **Added … as another terrain**.
3. Each terrain is listed under **Layers**. **Remove** takes one out again; with none left, the site is back on the plain globe. The DEM stays in the library.

Meridian looks up the ground's height at the twin's **origin** and builds the whole site level at that height. With several terrains, it's the height of the first the twin loads, and the site stays there when **Site on** moves it onto another survey: only the ground changes.

```text
which terrain, on a date (or now):
    of the dated terrains, the newest captured on or before the date
    else, the last undated one
    else (the date is before every survey), the earliest survey
```

## Tips

- **Put the origin on the pad.** The site is built at the ground height of its origin, so an origin on a slope lifts or sinks everything. Set the origin where the buildings stand.
- **The site is level.** On sloping ground, assets far from the origin can float above it or sink into it. Keep large sloping sites to a pad, or split them into several twins.
- **Cover more than the site.** A DEM a few hundred metres wider than the site gives the hills and shoreline around it, which is what makes terrain worth having.
- **Lining up with other data:** Meridian doesn't convert sea-level heights to the globe's ellipsoid. Within a twin that doesn't matter, but a georeferenced scan may sit some metres above or below the terrain. Nudge it with **Up** in the Layout panel.

## See also

- [Projects and the library](03-projects-and-the-library.md): uploading.
- [Point clouds](04-point-clouds.md): the same converter, for scans.
- [Comparing surveys](17-comparing-surveys.md): cut and fill between this DEM and others.
- [Map layers and imagery](16-map-layers-and-imagery.md): a GeoTIFF orthophoto, chosen as **Imagery**, laid over the terrain.
- [Running Meridian](14-running-meridian.md#the-converter): the converter service.
