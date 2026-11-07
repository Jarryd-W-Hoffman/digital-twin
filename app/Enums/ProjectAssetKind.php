<?php

declare(strict_types=1);

namespace App\Enums;

enum ProjectAssetKind: string
{
    case Model = 'model';
    case Tileset = 'tileset';
    case PointCloud = 'pointcloud';
    case Terrain = 'terrain';

    /**
     * Kinds are told apart by extension; the file's contents are checked when
     * it is processed.
     */
    public static function fromFilename(string $filename): self
    {
        return match (mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'zip' => self::Tileset,
            'las', 'laz', 'e57' => self::PointCloud,
            'tif', 'tiff', 'asc' => self::Terrain,
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
     * Point clouds and DEMs are kept as uploaded and converted on the queue.
     */
    public function isConverted(): bool
    {
        return $this === self::PointCloud || $this === self::Terrain;
    }
}
