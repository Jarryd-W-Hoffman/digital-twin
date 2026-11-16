<?php

declare(strict_types=1);

namespace App\Enums;

enum ProjectAssetKind: string
{
    case Model = 'model';
    case Tileset = 'tileset';
    case PointCloud = 'pointcloud';
    case Terrain = 'terrain';
    case Bim = 'bim';
    case Vector = 'vector';
    case Imagery = 'imagery';
    case Mesh = 'mesh';

    /**
     * Kinds are told apart by extension; the file's contents are checked when
     * it is processed. Two extensions are ambiguous, so the uploader says: a
     * GeoTIFF is terrain unless it's `imagery`, and a zip is a tileset unless
     * it's a `vector` (a zipped shapefile), a `mesh` (a photogrammetry
     * export) or `opendronemap` (an OpenDroneMap export: its 3D model, with
     * its other outputs split off).
     */
    public static function fromFilename(string $filename, ?string $as = null): self
    {
        return match (mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'zip' => self::zipped($as),
            'obj' => self::Mesh,
            'las', 'laz', 'e57' => self::PointCloud,
            'tif', 'tiff' => $as === self::Imagery->value ? self::Imagery : self::Terrain,
            'asc' => self::Terrain,
            'ifc' => self::Bim,
            'geojson', 'json', 'kml', 'kmz', 'dxf', 'gpkg' => self::Vector,
            default => self::Model,
        };
    }

    /**
     * Point clouds and meshes are converted to 3D Tiles, so twins show them as
     * tilesets.
     */
    public function isTileset(): bool
    {
        return in_array($this, [self::Tileset, self::PointCloud, self::Mesh], true);
    }

    /**
     * Point clouds, DEMs, IFC models, map layers, imagery and meshes are kept
     * as uploaded and converted on the queue.
     */
    public function isConverted(): bool
    {
        return $this !== self::Model && $this !== self::Tileset;
    }

    /**
     * A zip is a tileset, unless it's a zipped shapefile, a photogrammetry
     * export, or OpenDroneMap's (a mesh to split first).
     */
    private static function zipped(?string $as): self
    {
        if ($as === 'opendronemap') {
            return self::Mesh;
        }

        return in_array($as, [self::Vector->value, self::Mesh->value], true) ? self::from((string) $as) : self::Tileset;
    }
}
