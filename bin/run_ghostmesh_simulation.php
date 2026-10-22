#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use GhostMesh\Domain\Entities\AnonymousKeyPair;
use GhostMesh\Domain\Entities\E2eeCiphertextEnvelope;
use GhostMesh\Domain\Enums\BurnTimerPolicy;
use GhostMesh\Service\ClientCryptoEngine;
use GhostMesh\Service\DoubleRatchetEnvelopeService;
use GhostMesh\Service\BlindMessageRelayService;
use GhostMesh\Service\BurnOnReadRoomService;
use GhostMesh\Service\ProofOfWorkRateLimiterService;
use GhostMesh\Security\GhostMeshSecurityFilter;

function line(string $text = ''): void
{
    echo $text . PHP_EOL;
}

function section(string $title): void
{
    $pad = str_repeat('-', 60);
    line($pad);
    line('  ' . strtoupper($title));
    line($pad);
}

function step(string $label, string $value): void
{
    printf("  %-35s %s\n", $label, $value);
}

line();
line('  GhostMesh Secure Chat — End-to-End Simulation');
line('  Zero-Knowledge Platform v1.0.0');
line();

// ---------------------------------------------------------------
// PHASE 1: Keypair Generation
// ---------------------------------------------------------------
section('Phase 1 — Ephemeral X25519 Keypair Generation');

$alice = AnonymousKeyPair::generateEphemeralX25519();
$bob   = AnonymousKeyPair::generateEphemeralX25519();

step('Alice pubkey fingerprint:', $alice->fingerprint);
step('Bob pubkey fingerprint:',   $bob->fingerprint);
line();

// ---------------------------------------------------------------
// PHASE 2: Proof-of-Work Anti-Spam
// ---------------------------------------------------------------
section('Phase 2 — Proof-of-Work Challenge (Anti-Spam)');

$pow       = new ProofOfWorkRateLimiterService();
$challenge = $pow->issueChallenge(difficultyBits: 8);

step('Challenge seed:', $challenge['challenge_seed']);
step('Difficulty bits:', (string) $challenge['difficulty_bits']);

// Solve PoW
$nonce = 0;
do {
    $candidate = dechex($nonce++);
} while (!$pow->verifyProofOfWork($challenge['challenge_seed'], $candidate, $challenge['difficulty_bits']));

step('Nonce ditemukan:', $candidate);
step('Verifikasi PoW:', 'PASSED');
line();

// ---------------------------------------------------------------
// PHASE 3: X25519 ECDH Key Exchange
// ---------------------------------------------------------------
section('Phase 3 — X25519 ECDH Key Exchange');

$engine = new ClientCryptoEngine();

$aliceSharedHex = $engine->computeSharedSecret($alice->privateKeyHex, $bob->publicKeyHex);
$bobSharedHex   = $engine->computeSharedSecret($bob->privateKeyHex, $alice->publicKeyHex);

step('Alice shared secret (16 chars):', substr($aliceSharedHex, 0, 16) . '...');
step('Bob shared secret (16 chars):',   substr($bobSharedHex, 0, 16) . '...');
step('Shared secret match:', $aliceSharedHex === $bobSharedHex ? 'YES — ECDH OK' : 'NO — MISMATCH ERROR');
line();

// ---------------------------------------------------------------
// PHASE 4: HKDF Key Derivation
// ---------------------------------------------------------------
section('Phase 4 — HKDF Key Derivation');

$saltHex      = bin2hex(random_bytes(32));
$messageKeyHex = $engine->deriveMessageKey($aliceSharedHex, $saltHex, 'ghostmesh-v1-message-key');

step('Salt (32 bytes hex, 16 chars):', substr($saltHex, 0, 16) . '...');
step('Derived message key (16 chars):', substr($messageKeyHex, 0, 16) . '...');
line();

// ---------------------------------------------------------------
// PHASE 5: Encrypt Message (ChaCha20-Poly1305 + Fixed Padding)
// ---------------------------------------------------------------
section('Phase 5 — ChaCha20-Poly1305 Encryption + Fixed-Size Padding');

$ratchet      = new DoubleRatchetEnvelopeService();
$inboxToken   = bin2hex(random_bytes(16));
$plaintext    = 'pesan rahasia dari alice ke bob — ini tidak bisa dibaca server';

$envelope = $ratchet->encryptAndPad(
    plaintext:       $plaintext,
    symmetricKeyHex: $messageKeyHex,
    inboxToken:      $inboxToken,
    senderPubkeyHex: $alice->publicKeyHex,
    burnPolicy:      BurnTimerPolicy::BURN_ON_READ
);

step('Plaintext length:', strlen($plaintext) . ' bytes');
step('Padded+encrypted length:', $envelope->paddedLength . ' bytes (fixed 1024)');
step('Nonce (12 bytes):', substr($envelope->nonceHex, 0, 16) . '...');
step('MAC tag (16 bytes):', substr($envelope->macTagHex, 0, 16) . '...');
step('Burn policy:', $envelope->burnPolicy->value);
line();

// ---------------------------------------------------------------
// PHASE 6: Blind Relay (Server Tidak Bisa Baca Isi Pesan)
// ---------------------------------------------------------------
section('Phase 6 — Blind Message Relay');

$relay  = new BlindMessageRelayService();
$queued = $relay->acceptBlindMessage($envelope);

step('Status:', $queued['status']);
step('Routing token (16 chars):', substr($queued['routing_token'], 0, 16) . '...');
step('Burn policy:', $queued['burn_policy']);
line();

// ---------------------------------------------------------------
// PHASE 7: Nonce Replay Attack Detection
// ---------------------------------------------------------------
section('Phase 7 — Nonce Replay Attack Detection');

$secFilter   = new GhostMeshSecurityFilter();
$seenNonces  = [];
$testNonce   = bin2hex(random_bytes(12));

$secFilter->assertNonceFreshness($testNonce, $seenNonces);
step('Nonce pertama:', 'ACCEPTED');

try {
    $secFilter->assertNonceFreshness($testNonce, $seenNonces);
    step('Nonce replay:', 'ERROR — replay seharusnya ditolak');
} catch (RuntimeException $e) {
    step('Nonce replay terdeteksi:', 'BLOCKED — ' . substr($e->getMessage(), 0, 30) . '...');
}
line();

// ---------------------------------------------------------------
// PHASE 8: Decrypt + Unpad (Bob Side)
// ---------------------------------------------------------------
section('Phase 8 — Decrypt & Unpad (Bob Side)');

$decryptedBobKey = $engine->deriveMessageKey($bobSharedHex, $saltHex, 'ghostmesh-v1-message-key');
$decrypted       = $ratchet->decryptAndUnpad($envelope, $decryptedBobKey);

step('Decrypted plaintext:', $decrypted);
step('Integrity:', $decrypted === $plaintext ? 'VERIFIED — pesan identik' : 'FAIL — korup');
line();

// ---------------------------------------------------------------
// PHASE 9: Burn-on-Read
// ---------------------------------------------------------------
section('Phase 9 — Burn-on-Read Destruction');

$burnService = new BurnOnReadRoomService();
$receiptSig  = bin2hex(random_bytes(32));
$burnResult  = $burnService->executeBurnOnRead($inboxToken, $receiptSig);

step('Status:', $burnResult['status']);
step('Receipt hash (16 chars):', substr($burnResult['receipt_hash'], 0, 16) . '...');
step('Memory status:', $burnResult['memory_status']);
line();

// ---------------------------------------------------------------
// Wipe sensitive material
// ---------------------------------------------------------------
$sensitiveKey = $messageKeyHex;
$secFilter->wipeSensitiveBuffer($sensitiveKey);

line(str_repeat('=', 60));
line('  Simulasi selesai. Semua fase berhasil.');
line(str_repeat('=', 60));
line();