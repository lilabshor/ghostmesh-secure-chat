<?php
declare(strict_types=1);

namespace GhostMesh\Database\Seeders;

use PDO;
use Throwable;

class GhostMeshPlatformSeeder
{
    public function __construct(private PDO $pdo) {}

    public function run(): void
    {
        $this->pdo->beginTransaction();
        try {
            $nodeFingerprint = hash('sha256', 'ghostmesh_node_zero_log_2026');
            $this->pdo->exec("INSERT INTO ephemeral_nodes (id, node_fingerprint, software_version, is_active)
                VALUES (1, '{$nodeFingerprint}', '1.0.0-zero-knowledge', 1)
                ON DUPLICATE KEY UPDATE is_active = 1");

            $aliceIdentityPub  = hash('sha256', 'alice_identity_public_key_X25519_2026');
            $aliceSignedPrekey = hash('sha256', 'alice_signed_prekey_x25519_2026');
            $alicePrekeySig    = hash('sha512', 'alice_prekey_signature_ed25519_2026');

            $this->pdo->exec("INSERT INTO identity_prekey_bundles
                (id, identity_public_key_hex, signed_prekey_hex, prekey_signature_hex, one_time_prekeys_count)
                VALUES (1, '{$aliceIdentityPub}', '{$aliceSignedPrekey}', '{$alicePrekeySig}', 50)
                ON DUPLICATE KEY UPDATE one_time_prekeys_count = 50");

            $bobIdentityPub  = hash('sha256', 'bob_identity_public_key_X25519_2026');
            $bobSignedPrekey = hash('sha256', 'bob_signed_prekey_X25519_2026');
            $bobPrekeySig    = hash('sha512', 'bob_prekey_signature_ed25519_2026');

            $this->pdo->exec("INSERT INTO identity_prekey_bundles
                (id, identity_public_key_hex, signed_prekey_hex, prekey_signature_hex, one_time_prekeys_count)
                VALUES (2, '{$bobIdentityPub}', '{$bobSignedPrekey}', '{$bobPrekeySig}', 50)
                ON DUPLICATE KEY UPDATE one_time_prekeys_count = 50");

            $rndzToken  = hash('sha256', 'rendezvous_alice_bob_session_01');
            $inboxToken = hash('sha256', 'inbox_bob_receive_stream_01');
            $this->pdo->exec("INSERT INTO blind_rendezvous_tokens
                (id, rendezvous_token, target_inbox_token, expires_at)
                VALUES (1, '{$rndzToken}', '{$inboxToken}', DATE_ADD(NOW(), INTERVAL 24 HOUR))
                ON DUPLICATE KEY UPDATE expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR)");

            $this->pdo->commit();
            echo 'GhostMesh seeder selesai.' . PHP_EOL;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
