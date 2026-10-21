<?php
declare(strict_types=1);
namespace GhostMesh\Service;
use RuntimeException;
use InvalidArgumentException;

class ClientCryptoEngine
{
    public function computerSharedSecret(string $myPrivateKeyHex, string $peerPublicKeyHex) : string
    {
        $myPriv = hex2bin($myPrivateKeyHex);
        $peerPub = hex2bin($peerPublicKeyHex);

        if (strlen($myPriv) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES || strlen($peerPub) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new InvalidArgumentException("panjang kunci x25519 tidak valid (wajib 32 bytes) ");
        }

        $sharedSecret = sodium_crypto_scalarmult($myPriv, $peerPub);
        if ($sharedSecret === false) {
            throw new RuntimeException("gagal menghitung shared secret x25519");
        }

        return bin2hex($sharedSecret);
    }

    public function deriveMessageKey(string $sharedSecretHex, string $saltHex, string $infoContext): string
    {
        $sharedSecret = hex2bin($sharedSecretHex);
        $salt = hex2bin($saltHex);

        $prk = hash_hmac('sha256', $sharedSecret, $salt, true);
        $okm = hash_hmac('sha256', $infoContext . chr(1), $prk, true);

        return bin2hex($okm);
    }
}