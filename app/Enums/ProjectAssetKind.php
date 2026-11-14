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

    /**
     * Kinds are told apart by extension; the file's contents are checked when
     * it is processed. Two extensions are ambiguous, so the uploader says: a
     * GeoTIFF is terrain unless it's `imagery`, and a zip is a tileset unless
     * it's a `vector` (a zipped shapefile).
     */
    public static function fromFilename(string $filename, ?string $as = null): self
    {
        return match (mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'zip' => $as === self::Vector->value ? self::Vector : self::Tileset,
            'las', 'laz', 'e57' => self::PointCloud,
            'tif', 'tiff' => $as === self::Imagery->value ? self::Imagery : self::Terrain,
            'asc' => self::Terrain,
            'ifc' => self::Bim,
            'geojson', 'json', 'kml', 'kmz', 'dxf', 'gpkg' => self::Vector,
            default => self::Model,
        };
    }

    /**
     * Point clouds are converted to 3D Tiles, so twins show them as tilesets.
     */
    public function isTileset(): bool
    {
        return $this === self::Tileset || $this === self::PointCloud;
    }

    /**
     * Point clouds, DEMs, IFC models, map layers and imagery are kept as
     * uploaded and converted on the queue.
     */
    public function isConverted(): bool
    {
        return $this !== self::Model && $this !== self::Tileset;
    }
}
