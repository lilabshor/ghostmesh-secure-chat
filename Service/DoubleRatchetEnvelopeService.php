<?php
declare(strict_types=1);
namespace GhostMesh\Service;
use GhostMesh\Domain\Entities\E2eeCiphertextEnvelope;
use GhostMesh\Domain\Enums\BurnTimerPolicy;
use RuntimeException;

class DoubleRatchetEnvelopeService

{
    public const FIXED_PACKET_SIZE = 1024;

    public function encryptAndPad(
        string $plaintext,
        string $symmetricKeyHex,
        string $inboxToken,
        string $senderPubkeyHex,
        BurnTimerPolicy $burnPolicy
    ) : E2eeCiphertextEnvelope {
        $key = hex2bin($symmetricKeyHex);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_CHACHA20POLY1305_IETF_NPUBBYTES);

        $payloadWithLength = pack('N', strlen($plaintext)) . $plaintext;
        $padLength = max(0, self::FIXED_PACKET_SIZE - strlen($payloadWithLength));
        $paddedData = $payloadWithLength . random_bytes($padLength);
        $ciphertext = sodium_crypto_aead_chacha20poly1305_ietf_encrypt(
            $paddedData,
            $inboxToken,
            $nonce,
            $key
        );

        $tag = substr($ciphertext, -SODIUM_CRYPTO_AEAD_CHACHA20POLY1305_IETF_ABYTES);
        $rawCipher = substr($ciphertext, 0, -SODIUM_CRYPTO_AEAD_CHACHA20POLY1305_IETF_ABYTES);

        return new E2eeCiphertextEnvelope(
            inboxRoutingToken: $inboxToken,
            ephemeralSenderPubKeyHex: $senderPubkeyHex,
            ciphertextHex: bin2hex($rawCipher),
            nonceHex: bin2hex($nonce),
            macTagHex: bin2hex($tag),
            paddedLength: strlen($ciphertext),
            burnPolicy: $burnPolicy
        );
    }

    public function decryptAndUnpad(E2eeCiphertextEnvelope $envelope, string $symmetricKeyHex) : string
    {
     $key = hex2bin($symmetricKeyHex);
     $nonce = hex2bin($envelope -> nonceHex);
     $cipherWithTag = hex2bin($envelope->ciphertextHex) .hex2bin($envelope->macTagHex);

     $decryptedPadded = sodium_crypto_aead_chacha20poly1305_ietf_decrypt(
         $cipherWithTag,
         $envelope->inboxRoutingToken,
         $nonce,
         $key
     );
     if ($decryptedPadded === false) {
         throw new RuntimeException("gagal mendekripsikan: tag auth poly1305 tidak valid");
     }

        $originalLength = unpack('N', substr($decryptedPadded, 0, 4))[1];
        return substr($decryptedPadded, 4, $originalLength);
    }
}