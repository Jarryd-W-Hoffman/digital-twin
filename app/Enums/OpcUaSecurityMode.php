<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How an OPC UA connection protects its messages: not at all, signed, or
 * signed and encrypted.
 */
enum OpcUaSecurityMode: string
{
    case None = 'none';
    case Sign = 'sign';
    case SignAndEncrypt = 'signAndEncrypt';
}
