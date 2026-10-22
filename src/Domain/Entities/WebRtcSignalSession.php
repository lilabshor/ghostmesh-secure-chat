<?php
declare(strict_types=1);

namespace GhostMesh\Domain\Entities;

use GhostMesh\Domain\Enums\SignalingState;

class WebRtcSignalSession
{
    public function __construct(
        public readonly string        $sessionUuid,
        public readonly string        $initiatorPubkeyHash,
        public readonly string        $peerPubkeyHash,
        public readonly string        $encryptedSdpOffer,
        public readonly int           $createdAt,
        public readonly int           $expiresAt,
        public SignalingState         $state              = SignalingState::AWAITING_ANSWER,
        public ?string                $encryptedSdpAnswer = null
    ) {}

    public function isExpired(): bool
    {
        return time() >= $this->expiresAt;
    }

    public function toArray(): array
    {
        return [
            'session_uuid'    => $this->sessionUuid,
            'initiator_hash'  => $this->initiatorPubkeyHash,
            'peer_hash'       => $this->peerPubkeyHash,
            'state'           => $this->state->value,
            'created_at'      => $this->createdAt,
            'expires_at'      => $this->expiresAt,
        ];
    }
}