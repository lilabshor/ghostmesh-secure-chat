<?php
declare(strict_types=1);
namespace GhostMesh\Service;

class ProofOfWorkRateLimiterService
{
    public function issueChallenge(int $difficultyBits = 16) : array
    {
        $seed = bin2hex(random_bytes(16));
        $timestamp = time();

        return [
            'challenge_seed' => $seed,
            'difficulty_bits' => $difficultyBits,
            'issued_at' => $timestamp
        ];
    }

    public function verifyProofOfWork(string $challengeSeed, string $nonce, int $difficultyBits = 16) : bool
    {
        $hash = hash('sha256', $challengeSeed . '|' . $nonce);
        $requiredHexZeros = (int)ceil($difficultyBits / 4);
        $expectedPrefix = str_repeat('0', $requiredHexZeros);

        return str_starts_with($hash, $expectedPrefix);
    }
}