<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * An upload (a point cloud or a DEM) that couldn't be converted, with a reason fit to show its
 * uploader. Not reported: the reason is shown on the library item instead.
 */
final class ConversionFailed extends RuntimeException {}
