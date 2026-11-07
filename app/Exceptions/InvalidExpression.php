<?php

declare(strict_types=1);

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * An expression in a twin definition that doesn't parse, with what is wrong.
 */
final class InvalidExpression extends InvalidArgumentException {}
