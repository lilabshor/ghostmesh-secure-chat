<?php
declare(strict_types=1);

namespace GhostMesh\Domain\Entities;

use GhostMesh\Domain\Enums\BurnTimerPolicy;

class E2eeCiphertextEnvelope
{
    public function __construct(
        public readonly string          $inboxRoutingToken,
        public readonly string          $ephemeralSenderPubkeyHex,
        public readonly string          $ciphertextHex,
        public readonly string          $nonceHex,
        public readonly string          $macTagHex,
        public readonly int             $paddedLength = 1024,
        public readonly BurnTimerPolicy $burnPolicy   = BurnTimerPolicy::THIRTY_SECONDS
    ) {}

    public function toPayloadJson(): string
    {
        return json_encode([
            'token'       => $this->inboxRoutingToken,
            'sender_pub'  => $this->ephemeralSenderPubkeyHex,
            'ciphertext'  => $this->ciphertextHex,
            'nonce'       => $this->nonceHex,
            'mac_tag'     => $this->macTagHex,
            'padded_len'  => $this->paddedLength,
            'burn_policy' => $this->burnPolicy->value,
        ], JSON_THROW_ON_ERROR);
    }
}