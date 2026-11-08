<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The algorithms a signed or encrypted OPC UA connection uses. The values are
 * the names node-opcua knows them by; older policies (Basic128Rsa15, Basic256)
 * are deprecated by the OPC Foundation and left out.
 */
enum OpcUaSecurityPolicy: string
{
    case None = 'None';
    case Basic256Sha256 = 'Basic256Sha256';
    case Aes128Sha256RsaOaep = 'Aes128_Sha256_RsaOaep';
    case Aes256Sha256RsaPss = 'Aes256_Sha256_RsaPss';
}
