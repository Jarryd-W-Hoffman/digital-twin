<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A point cloud that couldn't be converted, with a reason fit to show its
 * uploader. Not reported: the reason is shown on the library item instead.
 */
final class PointCloudConversionFailed extends RuntimeException {}
