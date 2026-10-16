<?php
declare(strict_types=1);

namespace GhostMesh\Domain\Enums;

enum keyTYpe: string
{
    case X25519_KEY_EXCHANGE = 'X2559';
    case ED25519_SIGNATURE = 'ED25519';
    case CHACHA20_POLY1305_SYMMETRIC = 'CHACHA20_POLY1305';
}