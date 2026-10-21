<?php
declare(strict_types=1);

namespace GhostMesh\Repository;

use PDO;
use RuntimeException;

class EphemeralRamQueueRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function enqueueCiphertext(
        string $routingToken,
        string $senderPubkeyHex,
        string $ciphertextHex,
        string $macTagHex,
        int    $burnAfterSeconds
    ): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO blind_message_queues
 (inbox_routing_token, ephemeral_sender_pubkey_hex, encrypted_payload_ciphertext, mac_tag_hex,
 burn_after_seconds, expires_at)
 VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))");
        $stmt->execute([$routingToken, $senderPubkeyHex, $ciphertextHex, $macTagHex, $burnAfterSeconds]);
        return (int)$this->pdo->lastInsertId();
    }

    public function purgeMessageOnRead(int $queueId, string $receiptHash): void
    {
        $this->pdo->beginTransaction();
        try {
            // Hapus pesan secara permanen dari antrean transit
            $stmtDel = $this->pdo->prepare("DELETE FROM blind_message_queues WHERE id = ?");
            $stmtDel->execute([$queueId]);
            // Catat bukti pemusnahan
            $stmtReceipt = $this->pdo->prepare("INSERT INTO burn_on_read_receipts (message_queue_id, receipt_hash) VALUES (?, ?)"); $stmtReceipt->execute([$queueId, $receiptHash]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw new RuntimeException("Gagal mengeksekusi pemusnahan pesan: " . $e->getMessage(), 0, $e);
        }
    }
}
