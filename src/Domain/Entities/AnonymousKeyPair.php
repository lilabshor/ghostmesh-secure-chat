<?php
declare(strict_types=1);

namespace GhostMesh\Domain\Entities;

class AnonymousKeyPair
{
    public function __construct(
        public readonly string $publicKeyHex,
        public readonly string $privateKeyHex,
        public readonly string $fingerprint
    ) {}

    public static function generateEphemeralX25519(): self
    {
        $keypair = sodium_crypto_box_keypair();
        $pubKey  = sodium_crypto_box_publickey($keypair);
        $privKey = sodium_crypto_box_secretkey($keypair);

        $pubHex      = bin2hex($pubKey);
        $privHex     = bin2hex($privKey);
        $fingerprint = substr(hash('sha256', $pubKey), 0, 16);

        sodium_memzero($keypair);

        return new self($pubHex, $privHex, $fingerprint);
    }
}