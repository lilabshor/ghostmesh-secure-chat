<?php
declare(strict_types=1);
namespace GhostMesh\Security;
use RuntimeException;

class GhostMeshSecurityFilter
{
    public function assertNonceFreshness(string $nonceHex, array &$seenNoncesStore): void
    {
        if (isset($seenNoncesStore[$nonceHex])) {
            throw new RuntimeException("Eksploitasi Ditolak: Nonce telah digunakan sebelumnya (Replay Attack Detected)!");
        }
        $seenNoncesStore[$nonceHex] = time();
    }

    public function wipeSensitiveBuffer(string &$sensitiveData): void
    {
        if (function_exists('sodium_memzero')) {
            sodium_memzero($sensitiveData);
        } else {
            $sensitiveData = str_repeat('\0', strlen($sensitiveData));
        }
    }
}