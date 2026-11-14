"""
Writes a later survey of the Harbour Depot ground for the walkthrough: the
DEM from generate-terrain.py with a stockpile tipped east of the yard (a
cone 30 m across and 6 m high, about 1,400 m³) and a pit dug to its west
(30 by 20 m and 3 m deep, 1,800 m³). Runs in the converter image:

    docker run --rm -v "$PWD":/var/www/html meridian-converter sh -c \\
        'python3 scripts/generate-terrain.py /tmp/depot.tif &&
         python3 scripts/generate-survey.py /tmp/depot.tif /tmp/depot-may.tif'
"""

import sys

import numpy as np
from osgeo import gdal
from pyproj import Transformer

gdal.UseExceptions()

ORIGIN_LAT, ORIGIN_LON = -36.8395, 174.7855
STOCKPILE = (90, 70, 15, 6)  # east, north, radius and height in metres
PIT = (-80, 70, 30, 20, 3)  # east, north, width, length and depth in metres
RES_M = 1

before, after = sys.argv[1:3]
e0, n0 = Transformer.from_crs("EPSG:4326", "EPSG:2193", always_xy=True).transform(ORIGIN_LON, ORIGIN_LAT)

# A metre grid 400 m across, so the stockpile and pit have a shape.
dem = gdal.Warp(after, before, outputBounds=(e0 - 200, n0 - 200, e0 + 200, n0 + 200), xRes=RES_M, yRes=RES_M,
                resampleAlg="cubic", creationOptions=["COMPRESS=DEFLATE"])
band = dem.GetRasterBand(1)
heights = band.ReadAsArray()
cells = heights.shape[0]
offsets = (np.arange(cells) + 0.5) * RES_M - 200
east, north = np.meshgrid(offsets, -offsets)

x, y, radius, height = STOCKPILE
heights += np.clip(height * (1 - np.hypot(east - x, north - y) / radius), 0, None)
x, y, width, length, depth = PIT
heights[(np.abs(east - x) < width / 2) & (np.abs(north - y) < length / 2)] -= depth

band.WriteArray(heights)
dem = None
