<?php

declare(strict_types=1);

namespace App\Enums;

enum TwinAssetKind: string
{
    case Model = 'model';
    case Tileset = 'tileset';
}
