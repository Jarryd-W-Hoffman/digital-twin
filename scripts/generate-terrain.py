"""
Writes a synthetic DEM around the Harbour Depot sample, in NZTM (EPSG:2193):
the depot on a level pad at 12 m, ground rising to a 90 m ridge to the
south-west, and falling to the harbour (0 m) to the north. The sample's
terrain is this DEM tiled by meridian-terrain. Runs in the converter image:

    docker run --rm -v "$PWD":/var/www/html meridian-converter sh -c \\
        'python3 scripts/generate-terrain.py /tmp/depot.tif &&
         meridian-terrain /tmp/depot.tif public/samples/terrain/harbour-depot --max-zoom 15'
"""

import sys

import numpy as np
from osgeo import gdal, osr
from pyproj import Transformer

gdal.UseExceptions()

ORIGIN_LAT, ORIGIN_LON = -36.8395, 174.7855
SIZE_M, RES_M = 3000, 5
PAD_M, PAD_HEIGHT = 160, 12.0

origin_e, origin_n = Transformer.from_crs("EPSG:4326", "EPSG:2193", always_xy=True).transform(ORIGIN_LON, ORIGIN_LAT)
cells = SIZE_M // RES_M
offsets = (np.arange(cells) + 0.5) * RES_M - SIZE_M / 2
east, north = np.meshgrid(offsets, -offsets)

# Rolling ground: a ridge to the south-west, the harbour to the north.
ridge = 90 * np.exp(-(((east + 900) ** 2) / (2 * 450**2) + ((north + 800) ** 2) / (2 * 600**2)))
swell = 6 * np.sin(east / 210) * np.cos(north / 260)
shore = np.clip((north - 350) / 400, 0, 1)
ground = (PAD_HEIGHT + ridge + swell) * (1 - shore) ** 2

# The depot's level pad, eased into the ground around it.
distance = np.hypot(east, north)
blend = np.clip((distance - PAD_M) / 120, 0, 1)
blend = blend * blend * (3 - 2 * blend)
heights = PAD_HEIGHT * (1 - blend) + ground * blend

dataset = gdal.GetDriverByName("GTiff").Create(sys.argv[1], cells, cells, 1, gdal.GDT_Float32, ["COMPRESS=DEFLATE"])
dataset.SetGeoTransform((origin_e - SIZE_M / 2, RES_M, 0, origin_n + SIZE_M / 2, 0, -RES_M))
reference = osr.SpatialReference()
reference.ImportFromEPSG(2193)
dataset.SetProjection(reference.ExportToWkt())
dataset.GetRasterBand(1).WriteArray(heights.astype(np.float32))
dataset = None
