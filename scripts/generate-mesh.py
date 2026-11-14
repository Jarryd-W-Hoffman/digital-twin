#!/usr/bin/env python3
"""
Writes a small textured mesh like a drone survey's export, for tests and the
walkthrough: a 100 m by 60 m mound on a level pad, 2 m triangles, its texture
a chequerboard with a red north edge, so orientation shows at a glance.

Vertices are in metres east, north and up (OBJ written z-up, as photogrammetry
software exports projected coordinates), relative to an offset in NZTM2000
(EPSG:2193) just south of Auckland's Domain.

    --georeference metadata   metadata.xml, as ContextCapture and DJI Terra write
    --georeference pix4d      <name>_offset.xyz and <name>.prj, as Pix4D writes
    --georeference none       neither: a mesh in local metres
    --y-up                    vertices as (east, up, south), as some tools write

Usage: generate-mesh.py OUT_DIR [--georeference metadata|pix4d|none] [--zip FILE]
"""

import argparse
import math
import os
import struct
import zipfile
import zlib

OFFSET = (1757500.0, 5920500.0, 30.0)
NZTM_WKT = (
    'PROJCS["NZGD2000 / New Zealand Transverse Mercator 2000",GEOGCS["NZGD2000",'
    'DATUM["New_Zealand_Geodetic_Datum_2000",SPHEROID["GRS 1980",6378137,298.257222101]],'
    'PRIMEM["Greenwich",0],UNIT["degree",0.0174532925199433]],PROJECTION["Transverse_Mercator"],'
    'PARAMETER["latitude_of_origin",0],PARAMETER["central_meridian",173],PARAMETER["scale_factor",0.9996],'
    'PARAMETER["false_easting",1600000],PARAMETER["false_northing",10000000],UNIT["metre",1],'
    'AUTHORITY["EPSG","2193"]]'
)
WIDTH, DEPTH, STEP = 100, 60, 2


def height(x: float, y: float) -> float:
    """A 6 m mound in the middle of a level pad."""
    r = math.hypot((x - WIDTH / 2) / 30, (y - DEPTH / 2) / 18)
    return 6 * max(0.0, 1 - r * r)


def png(path: str, size: int = 256) -> None:
    rows = []
    for j in range(size):
        row = bytearray([0])
        for i in range(size):
            if j < size // 16:  # image top = north edge
                row += bytes((220, 40, 40))
            else:
                light = ((i // 32) + (j // 32)) % 2 == 0
                row += bytes((230, 230, 220) if light else (70, 110, 60))
        rows.append(bytes(row))
    raw = zlib.compress(b"".join(rows), 9)

    def chunk(kind: bytes, data: bytes) -> bytes:
        return struct.pack(">I", len(data)) + kind + data + struct.pack(">I", zlib.crc32(kind + data) & 0xFFFFFFFF)

    with open(path, "wb") as out:
        out.write(b"\x89PNG\r\n\x1a\n")
        out.write(chunk(b"IHDR", struct.pack(">IIBBBBB", size, size, 8, 2, 0, 0, 0)))
        out.write(chunk(b"IDAT", raw))
        out.write(chunk(b"IEND", b""))


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("out")
    parser.add_argument("--georeference", choices=["metadata", "pix4d", "none"], default="metadata")
    parser.add_argument("--zip")
    parser.add_argument("--y-up", action="store_true", help="write the OBJ y-up, as some tools do")
    args = parser.parse_args()
    os.makedirs(args.out, exist_ok=True)
    name = "survey"

    columns, rows = WIDTH // STEP + 1, DEPTH // STEP + 1
    lines = [f"mtllib {name}.mtl", "o survey"]
    for j in range(rows):
        for i in range(columns):
            x, y = i * STEP, j * STEP
            z = height(x, y)
            lines.append(f"v {x:.3f} {z:.3f} {-y:.3f}" if args.y_up else f"v {x:.3f} {y:.3f} {z:.3f}")
    for j in range(rows):
        for i in range(columns):
            lines.append(f"vt {i / (columns - 1):.5f} {j / (rows - 1):.5f}")
    lines.append("usemtl ground")
    for j in range(rows - 1):
        for i in range(columns - 1):
            a = j * columns + i + 1
            b, c, d = a + 1, a + columns, a + columns + 1
            lines.append(f"f {a}/{a} {b}/{b} {d}/{d}")
            lines.append(f"f {a}/{a} {d}/{d} {c}/{c}")
    with open(os.path.join(args.out, f"{name}.obj"), "w") as out:
        out.write("\n".join(lines) + "\n")
    with open(os.path.join(args.out, f"{name}.mtl"), "w") as out:
        out.write("newmtl ground\nKa 1 1 1\nKd 1 1 1\nmap_Kd texture.png\n")
    png(os.path.join(args.out, "texture.png"))

    if args.georeference == "metadata":
        with open(os.path.join(args.out, "metadata.xml"), "w") as out:
            out.write(
                '<?xml version="1.0" encoding="utf-8"?>\n<ModelMetadata version="1">\n'
                "\t<SRS>EPSG:2193</SRS>\n"
                f"\t<SRSOrigin>{OFFSET[0]},{OFFSET[1]},{OFFSET[2]}</SRSOrigin>\n"
                "\t<Texture>\n\t\t<ColorSource>Visible</ColorSource>\n\t</Texture>\n</ModelMetadata>\n"
            )
    elif args.georeference == "pix4d":
        with open(os.path.join(args.out, f"{name}_offset.xyz"), "w") as out:
            out.write(f"{OFFSET[0]} {OFFSET[1]} {OFFSET[2]}\n")
        with open(os.path.join(args.out, f"{name}.prj"), "w") as out:
            out.write(NZTM_WKT)

    if args.zip:
        with zipfile.ZipFile(args.zip, "w", zipfile.ZIP_DEFLATED) as archive:
            for file in sorted(os.listdir(args.out)):
                archive.write(os.path.join(args.out, file), f"export/{file}")


if __name__ == "__main__":
    main()
