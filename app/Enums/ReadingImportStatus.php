<?php

declare(strict_types=1);

namespace App\Enums;

enum ReadingImportStatus: string
{
    case Importing = 'importing';
    case Done = 'done';
    case Failed = 'failed';
}
