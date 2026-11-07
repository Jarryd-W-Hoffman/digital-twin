"""
Writes a synthetic laser scan of a wharf next to the Harbour Depot sample:
a sealed yard, a gabled shed, a row of shipping containers and a few
bollards, as coloured points in NZTM (EPSG:2193), the coordinate system New
Zealand surveys use. The manual's screenshots upload it to show a real
conversion. Runs in the converter image, which has numpy, laspy and pyproj:

    docker run --rm -v "$PWD":/var/www/html meridian-converter:dev \
        python3 scripts/generate-point-cloud.py test-results/wharf.las
"""

import sys

import laspy
import numpy as np
from pyproj import CRS, Transformer

rng = np.random.default_rng(7)

# Harbour Depot's origin. The yard is laid out in metres east and north of it, then
# moved into NZTM, so the scan lands beside the depot with no placement.
ORIGIN_LAT, ORIGIN_LON = -36.8395, 174.7855
to_nztm = Transformer.from_crs("EPSG:4326", "EPSG:2193", always_xy=True)
origin_e, origin_n = to_nztm.transform(ORIGIN_LON, ORIGIN_LAT)

parts = []


def add(xyz, rgb, jitter=0.02):
    xyz = xyz + rng.normal(0, jitter, xyz.shape)
    shade = rng.uniform(0.9, 1.1, (len(xyz), 1))
    parts.append((xyz, np.clip(np.asarray(rgb) * shade, 0, 255)))


def plane(n, x, y, z):
    """n points on an axis-aligned rectangle; each of x, y, z is a value or (lo, hi)."""
    pick = lambda v: rng.uniform(*v, n) if isinstance(v, tuple) else np.full(n, float(v))
    return np.column_stack([pick(x), pick(y), pick(z)])


def box(n, x0, x1, y0, y1, z0, z1, rgb):
    areas = [(x1 - x0) * (z1 - z0)] * 2 + [(y1 - y0) * (z1 - z0)] * 2 + [(x1 - x0) * (y1 - y0)]
    counts = (n * np.array(areas) / sum(areas)).astype(int)
    add(plane(counts[0], (x0, x1), y0, (z0, z1)), rgb)
    add(plane(counts[1], (x0, x1), y1, (z0, z1)), rgb)
    add(plane(counts[2], x0, (y0, y1), (z0, z1)), rgb)
    add(plane(counts[3], x1, (y0, y1), (z0, z1)), rgb)
    add(plane(counts[4], (x0, x1), (y0, y1), z1), [c * 0.85 for c in rgb])


# The yard: 90 m by 70 m of asphalt, with painted lane lines.
yard = plane(260_000, (50, 140), (-35, 35), 0)
add(yard, [72, 74, 78], jitter=0.01)
for y in (-20, 0, 20):
    add(plane(4_000, (55, 135), (y - 0.1, y + 0.1), 0.01), [225, 205, 70], jitter=0.005)

# The shed: walls of corrugated grey, and a pitched roof rising to 11 m.
box(90_000, 60, 100, 8, 30, 0, 8, [168, 172, 176])
ridge = rng.uniform(0, 1, 50_000)
side = rng.integers(0, 2, 50_000)
roof_y = np.where(side == 0, 8 + ridge * 11, 30 - ridge * 11)
add(np.column_stack([rng.uniform(60, 100, 50_000), roof_y, 8 + ridge * 3]), [140, 60, 52])

# A row of 12 m containers, stacked two high at one end.
colours = [[176, 58, 46], [41, 98, 160], [33, 133, 89], [214, 137, 16], [120, 120, 125], [176, 58, 46]]
for i, rgb in enumerate(colours):
    x0 = 108 + i * 3.2
    box(9_000, x0, x0 + 2.45, -28, -16, 0, 2.6, rgb)
    if i < 2:
        box(9_000, x0, x0 + 2.45, -28, -16, 2.6, 5.2, colours[-1 - i])

# Bollards along the wharf edge.
for x in range(55, 140, 10):
    theta = rng.uniform(0, 2 * np.pi, 800)
    add(np.column_stack([x + 0.3 * np.cos(theta), -34.5 + 0.3 * np.sin(theta), rng.uniform(0, 0.9, 800)]), [230, 230, 225], 0.005)

xyz = np.concatenate([p[0] for p in parts])
rgb = np.concatenate([p[1] for p in parts]).astype(np.uint16) * 256

header = laspy.LasHeader(point_format=2, version="1.2")
header.add_crs(CRS.from_epsg(2193))
header.offsets = [origin_e, origin_n, 0]
header.scales = [0.001, 0.001, 0.001]
las = laspy.LasData(header)
las.x = origin_e + xyz[:, 0]
las.y = origin_n + xyz[:, 1]
las.z = xyz[:, 2]
las.red, las.green, las.blue = rgb[:, 0], rgb[:, 1], rgb[:, 2]
las.write(sys.argv[1] if len(sys.argv) > 1 else "wharf.las")
print(f"{len(xyz):,} points")
