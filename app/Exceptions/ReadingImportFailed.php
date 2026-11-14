<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A file of readings that couldn't be imported, with a reason fit to show
 * whoever uploaded it. Not reported: the reason is shown on the import.
 */
final class ReadingImportFailed extends RuntimeException {}
