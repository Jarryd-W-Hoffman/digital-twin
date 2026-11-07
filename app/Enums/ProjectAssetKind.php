<?php

declare(strict_types=1);

namespace App\Enums;

enum ProjectAssetKind: string
{
    case Model = 'model';
    case Tileset = 'tileset';

    /**
     * Zipped tilesets and GLB models are told apart by extension; the file's
     * contents are checked when it is processed.
     */
    public static function fromFilename(string $filename): self
    {
        return mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'zip' ? self::Tileset : self::Model;
    }
}
