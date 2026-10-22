<?php
declare(strict_types=1);

namespace GhostMesh\Service;

use InvalidArgumentException;
use RuntimeException;
use SodiumException;

class ClientCryptoEngine
{
    public function computeSharedSecret(string $myPrivateKeyHex, string $peerPublicKeyHex): string
    {
        $myPriv  = hex2bin($myPrivateKeyHex);
        $peerPub = hex2bin($peerPublicKeyHex);

        if ($myPriv === false || strlen($myPriv) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
            throw new InvalidArgumentException('private key X25519 tidak valid (wajib 32 bytes hex)');
        }

        if ($peerPub === false || strlen($peerPub) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new InvalidArgumentException('public key X25519 peer tidak valid (wajib 32 bytes hex)');
        }

        try {
            $sharedSecret = sodium_crypto_scalarmult($myPriv, $peerPub);
        } catch (SodiumException $e) {
            throw new RuntimeException('gagal menghitung shared secret X25519: ' . $e->getMessage());
        } finally {
            sodium_memzero($myPriv);
        }

        if (strlen($sharedSecret) !== SODIUM_CRYPTO_SCALARMULT_BYTES) {
            sodium_memzero($sharedSecret);
            throw new RuntimeException('shared secret X25519 panjangnya tidak valid');
        }

        $hex = bin2hex($sharedSecret);
        sodium_memzero($sharedSecret);

        return $hex;
    }

    public function deriveMessageKey(string $sharedSecretHex, string $saltHex, string $infoContext): string
    {
        $sharedSecret = hex2bin($sharedSecretHex);
        $salt         = hex2bin($saltHex);

        if ($sharedSecret === false || $salt === false) {
            throw new InvalidArgumentException('sharedSecretHex atau saltHex bukan hex string yang valid');
        }

        $prk = hash_hmac('sha256', $sharedSecret, $salt, true);
        $okm = hash_hmac('sha256', $infoContext . chr(1), $prk, true);

        sodium_memzero($sharedSecret);
        sodium_memzero($prk);

        return bin2hex($okm);
    }
}