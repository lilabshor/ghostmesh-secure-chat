<?php
declare(strict_types=1);

namespace GhostMesh\Domain\Entities;

use GhostMesh\Domain\Enums\BurnTimerPolicy;
use InvalidArgumentException;

class EphemeralRoom
{
    public array $participantHashes = [];

    public function __construct(
        public readonly int             $id,
        public readonly string          $roomHash,
        public readonly int             $maxParticipants,
        public readonly BurnTimerPolicy $burnPolicy,
        public readonly int             $autoDestroyTimestamp
    ) {}

    public function addParticipant(string $pubKeyHash): void
    {
        if (count($this->participantHashes) >= $this->maxParticipants) {
            throw new InvalidArgumentException('room sudah mencapai kapasitas maksimal');
        }

        if (!in_array($pubKeyHash, $this->participantHashes, true)) {
            $this->participantHashes[] = $pubKeyHash;
        }
    }

    public function isExpired(int $currentTimestamp): bool
    {
        return $currentTimestamp >= $this->autoDestroyTimestamp;
    }
}