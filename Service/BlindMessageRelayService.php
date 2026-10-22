<?php
declare(strict_types=1);

namespace GhostMesh\Service;

use GhostMesh\Domain\Entities\E2eeCiphertextEnvelope;
use RuntimeException;

class BlindMessageRelayService
{
    public function acceptBlindMessage(E2eeCiphertextEnvelope $envelope, int $ttlSeconds = 3600): array
    {
        if ($envelope->paddedLength < DoubleRatchetEnvelopeService::FIXED_PACKET_SIZE) {
            throw new RuntimeException('paket ditolak: ukuran tidak memenuhi standar fixed-size packet');
        }

        $now             = time();
        $expiryTimestamp = $now + $ttlSeconds;

        return [
            'status'        => 'BLIND_ENQUEUED',
            'routing_token' => $envelope->inboxRoutingToken,
            'burn_policy'   => $envelope->burnPolicy->value,
            'enqueued_at'   => $now,
            'expires_at'    => $expiryTimestamp,
            'raw_envelope'  => $envelope->toPayloadJson(),
        ];
    }
}