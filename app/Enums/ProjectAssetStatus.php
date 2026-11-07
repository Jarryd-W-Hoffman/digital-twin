<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a library upload is: its bytes still arriving in object storage,
 * being checked and unpacked by a queued job, ready for twins, or refused.
 */
enum ProjectAssetStatus: string
{
    case Uploading = 'uploading';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
