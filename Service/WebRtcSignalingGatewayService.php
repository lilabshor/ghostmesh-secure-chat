<?php
declare(strict_types=1);
namespace GhostMesh\Service;
use RuntimeException;

class WebRtcSignalingGatewayService
{
    public function initiateSignalingSession (
            string $initiatorPubkeyHash,
            string $peerPubkeyHash,
            string $encryptedSdpOffer,
            int $ttlSeconds = 300
    ) : array {
        $sessionUuid = 'WTC-' . bin2hex(random_bytes(16));
        $now = time();

        return [
                'session_uuid' => $sessionUuid,
                'initiator_hash' => $initiatorPubkeyHash,
                'peer_hash' => $peerPubkeyHash,
                'encrypted_offer' => $encryptedSdpOffer,
                'state' => 'AWAITING_ANSWER',
                $now,
                $now + $ttlSeconds
        ];
    }

    public function relaySdpAnswer(array &$session, string $encryptedSdpAnswer) : array
    {
        if ($session['state'] !== 'AWAITING_ANSWER') {
            throw new RuntimeException("sesi WebRTC telah kadaluwarsa");
        }

        $session['encrypted_answer'] = $encryptedSdpAnswer;
        $session['state'] = 'PEER_CONNECTED';

        return $session;
    }
}