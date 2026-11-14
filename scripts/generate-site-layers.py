"""
Writes a synthetic orthophoto and site plan around the Harbour Depot sample,
in NZTM (EPSG:2193), for the walkthrough to upload: the photo is the depot's
DEM (from generate-terrain.py) shaded and coloured, with the yard, road and
sheds painted on; the plan is a DXF drawing (with no coordinate system, as
CAD drawings usually have) of the lot boundary, the yard fence and a
stormwater line. Runs in the converter image:

    docker run --rm -v "$PWD":/var/www/html meridian-converter sh -c \\
        'python3 scripts/generate-terrain.py /tmp/depot.tif &&
         python3 scripts/generate-site-layers.py /tmp/depot.tif /tmp/ortho.tif /tmp/site.dxf'
"""

import sys

import numpy as np
from osgeo import gdal, ogr
from pyproj import Transformer

gdal.UseExceptions()
ogr.UseExceptions()

ORIGIN_LAT, ORIGIN_LON = -36.8395, 174.7855
SIZE_M, RES_M = 1600, 1.0

dem_path, ortho_path, dxf_path = sys.argv[1:4]
e0, n0 = Transformer.from_crs("EPSG:4326", "EPSG:2193", always_xy=True).transform(ORIGIN_LON, ORIGIN_LAT)
half = SIZE_M / 2
bounds = (e0 - half, n0 - half, e0 + half, n0 + half)

# The DEM around the depot at the photo's resolution, then lit from the north-west.
dem = gdal.Warp("/vsimem/dem.tif", dem_path, outputBounds=bounds, xRes=RES_M, yRes=RES_M, resampleAlg="cubic")
heights = dem.GetRasterBand(1).ReadAsArray().astype(np.float32)
lit = gdal.DEMProcessing("/vsimem/shade.tif", dem, "hillshade", azimuth=315, altitude=35, zFactor=3)
shade = lit.GetRasterBand(1).ReadAsArray() / 255.0

cells = heights.shape[0]
offsets = (np.arange(cells) + 0.5) * RES_M - half
east, north = np.meshgrid(offsets, -offsets)
rng = np.random.default_rng(7)
grain = rng.normal(0, 0.04, heights.shape)

# Grass on the slopes, browner up high, water where the ground meets the harbour.
grass = np.stack([0.33, 0.45, 0.24])[:, None, None] * (1 + grain)
dry = np.stack([0.55, 0.50, 0.33])[:, None, None]
up = np.clip((heights - 20) / 60, 0, 1)
colour = grass * (1 - up) + dry * up
colour = colour * (0.55 + 0.7 * shade)
water = heights < 0.5
colour[:, water] = np.stack([0.12, 0.26, 0.36])[:, None] + grain[water] * 0.2

def paint(mask, rgb):
    colour[:, mask] = np.stack(rgb)[:, None] * (0.9 + grain[mask] * 0.5 + 0.2 * shade[mask])

paint(np.hypot(east, north) < 150, [0.58, 0.58, 0.56])                         # the sealed yard
paint((np.abs(north + 10) < 5) & (east < -140), [0.30, 0.30, 0.32])             # the road in
paint((np.abs(east - 30) < 32) & (np.abs(north - 20) < 18), [0.78, 0.80, 0.82])  # the cold store roof
paint((np.abs(east + 45) < 20) & (np.abs(north + 40) < 12), [0.62, 0.30, 0.24])  # the workshop roof
for row in range(6):                                                             # parked trailers
    paint((np.abs(east + 60 + row * 7) < 1.5) & (np.abs(north - 70) < 7), [0.85, 0.85, 0.85])

ortho = gdal.GetDriverByName("GTiff").Create(ortho_path, cells, cells, 3, gdal.GDT_Byte, ["COMPRESS=DEFLATE", "TILED=YES"])
ortho.SetGeoTransform(dem.GetGeoTransform())
ortho.SetProjection(dem.GetProjection())
for band in range(3):
    ortho.GetRasterBand(band + 1).WriteArray((np.clip(colour[band], 0, 1) * 255).astype(np.uint8))
ortho = None

# The plan, in NZTM metres but saying nothing of it.
plan = ogr.GetDriverByName("DXF").CreateDataSource(dxf_path)
entities = plan.CreateLayer("entities")

def line(points):
    feature = ogr.Feature(entities.GetLayerDefn())
    feature.SetGeometry(ogr.CreateGeometryFromWkt("LINESTRING (" + ", ".join(f"{e0 + e:.2f} {n0 + n:.2f}" for e, n in points) + ")"))
    entities.CreateFeature(feature)

line([(-210, -190), (230, -190), (230, 170), (-210, 170), (-210, -190)])        # the lot boundary
fence = [(150 * np.cos(a), 150 * np.sin(a)) for a in np.linspace(0, 2 * np.pi, 49)]
line(fence)                                                                      # the yard fence
line([(-80, -120), (-20, -60), (40, 10), (90, 120), (130, 260)])                # the stormwater line to the harbour
plan = None
