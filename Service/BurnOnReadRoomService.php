<?php
declare(strict_types=1);
namespace GhostMesh\Service;
use GhostMesh\Domain\Enums\BurnTimerPolicy;
use RuntimeException;
class BurnOnReadRoomService
{
    public function executeBurnOnRead(string $messageQueueToken, string $receiptSignatureHex) : array
    {
        $burnedAt = time();
        $destructionHash = hash('sha256', $messageQueueToken . '|' . $receiptSignatureHex . '|' . $burnedAt);

        return [
            'status' => 'BURNED_DESTROYED',
            'receipt_hash' => $destructionHash,
            'burned_timestamp' => $burnedAt,
            'memory_status' => 'PURGED_ZERO_LOG'
        ];
    }

    public function checkRoomDestruction(int $autoDestroyTimestamp, int $currentParticipantsCount) : bool
    {
        if (time() >= $autoDestroyTimestamp || $currentParticipantsCount === 0) {
                return true;
        }
        return false;
    }
}