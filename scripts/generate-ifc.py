#!/usr/bin/env python3
"""
Writes a small synthetic IFC4 building for tests and screenshots: a 20 m by
12 m shed on one storey, with a slab, four walls and a roof, in millimetres
so unit handling is exercised. Run it in the converter image, which has
IfcOpenShell:

    docker run --rm -v "$PWD":/var/www/html meridian-converter \\
        python scripts/generate-ifc.py shed.ifc --map EPSG:2193 1756100 5920100 30

--map places it with an IfcMapConversion (CRS, eastings, northings, and the
rotation of its x axis in degrees anticlockwise from grid east); --site with
IfcSite's RefLatitude and RefLongitude instead. --offset draws it that far
from the project origin, east and north in metres, to check recentring.
"""

import argparse
import math

import ifcopenshell
import ifcopenshell.api.aggregate
import ifcopenshell.api.context
import ifcopenshell.api.georeference
import ifcopenshell.api.geometry
import ifcopenshell.api.root
import ifcopenshell.api.spatial
import ifcopenshell.api.style
import ifcopenshell.api.unit
import numpy as np

COLOURS = {"slab": (0.55, 0.55, 0.52), "wall": (0.78, 0.74, 0.66), "roof": (0.35, 0.42, 0.5)}


def dms(value: float) -> list[int]:
    """Degrees as an IfcCompoundPlaneAngleMeasure."""
    sign = -1 if value < 0 else 1
    value = abs(value)
    d = int(value)
    m = int((value - d) * 60)
    s = int((value - d - m / 60) * 3600)
    u = int(round(((value - d - m / 60) * 3600 - s) * 1e6))
    return [sign * d, sign * m, sign * s, sign * u]


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("out")
    parser.add_argument("--map", nargs=4, metavar=("CRS", "EAST", "NORTH", "ROTATION"))
    parser.add_argument("--site", nargs=2, type=float, metavar=("LAT", "LON"))
    parser.add_argument("--true-north", type=float, default=0.0, help="Degrees the project's y axis is turned east of true north (with --site)")
    parser.add_argument("--offset", nargs=2, type=float, default=[0.0, 0.0])
    args = parser.parse_args()

    model = ifcopenshell.file(schema="IFC4")
    project = ifcopenshell.api.root.create_entity(model, ifc_class="IfcProject", name="Depot shed")
    length = ifcopenshell.api.unit.add_si_unit(model, unit_type="LENGTHUNIT", prefix="MILLI")
    ifcopenshell.api.unit.assign_unit(model, units=[length])
    context = ifcopenshell.api.context.add_context(model, context_type="Model")
    body = ifcopenshell.api.context.add_context(
        model, context_type="Model", context_identifier="Body", target_view="MODEL_VIEW", parent=context
    )

    site = ifcopenshell.api.root.create_entity(model, ifc_class="IfcSite", name="Depot")
    building = ifcopenshell.api.root.create_entity(model, ifc_class="IfcBuilding", name="Shed")
    storey = ifcopenshell.api.root.create_entity(model, ifc_class="IfcBuildingStorey", name="Ground floor")
    ifcopenshell.api.aggregate.assign_object(model, products=[site], relating_object=project)
    ifcopenshell.api.aggregate.assign_object(model, products=[building], relating_object=site)
    ifcopenshell.api.aggregate.assign_object(model, products=[storey], relating_object=building)

    if args.map:
        crs, east, north, rotation = args.map
        ifcopenshell.api.georeference.add_georeferencing(model)
        theta = math.radians(float(rotation))
        ifcopenshell.api.georeference.edit_georeferencing(
            model,
            projected_crs={"Name": crs},
            coordinate_operation={
                "Eastings": float(east), "Northings": float(north), "OrthogonalHeight": 0.0,
                "XAxisAbscissa": math.cos(theta), "XAxisOrdinate": math.sin(theta), "Scale": 0.001,
            },
        )
    if args.site:
        site.RefLatitude, site.RefLongitude = dms(args.site[0]), dms(args.site[1])
        site.RefElevation = 0.0
        turn = math.radians(args.true_north)
        context.TrueNorth = model.createIfcDirection((-math.sin(turn), math.cos(turn)))

    styles = {}
    for kind, (r, g, b) in COLOURS.items():
        style = ifcopenshell.api.style.add_style(model, name=kind)
        ifcopenshell.api.style.add_surface_style(
            model, style=style, ifc_class="IfcSurfaceStyleShading",
            attributes={"SurfaceColour": {"Name": None, "Red": r, "Green": g, "Blue": b}},
        )
        styles[kind] = style

    ox, oy = args.offset[0] * 1000, args.offset[1] * 1000

    def place(element, kind, representation, x, y, z, angle=0.0):
        """Positions are in millimetres, the file's units."""
        ifcopenshell.api.geometry.assign_representation(model, product=element, representation=representation)
        ifcopenshell.api.style.assign_representation_styles(model, shape_representation=representation, styles=[styles[kind]])
        matrix = np.eye(4)
        c, s = math.cos(math.radians(angle)), math.sin(math.radians(angle))
        matrix[:2, :2] = [[c, -s], [s, c]]
        matrix[:3, 3] = [ox + x, oy + y, z]
        ifcopenshell.api.geometry.edit_object_placement(model, product=element, matrix=matrix, is_si=False)
        ifcopenshell.api.spatial.assign_container(model, products=[element], relating_structure=storey)

    slab = ifcopenshell.api.root.create_entity(model, ifc_class="IfcSlab", name="Floor slab")
    # Representation sizes are in metres; IfcOpenShell scales them to the file's units.
    outline = [(0, 0), (20, 0), (20, 12), (0, 12)]
    place(slab, "slab", ifcopenshell.api.geometry.add_slab_representation(model, context=body, depth=0.2, polyline=outline), 0, 0, -200)

    for name, x, y, angle, length in [
        ("South wall", 0, 0, 0, 20),
        ("East wall", 20000, 0, 90, 12),
        ("North wall", 20000, 12000, 180, 20),
        ("West wall", 0, 12000, 270, 12),
    ]:
        wall = ifcopenshell.api.root.create_entity(model, ifc_class="IfcWall", name=name)
        place(wall, "wall", ifcopenshell.api.geometry.add_wall_representation(model, context=body, length=length, height=5, thickness=0.2), x, y, 0, angle)

    roof = ifcopenshell.api.root.create_entity(model, ifc_class="IfcRoof", name="Roof")
    place(roof, "roof", ifcopenshell.api.geometry.add_slab_representation(model, context=body, depth=0.15, polyline=outline), 0, 0, 5000)

    model.write(args.out)


if __name__ == "__main__":
    main()
