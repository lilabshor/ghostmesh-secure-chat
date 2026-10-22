<?php
declare(strict_types=1);

namespace GhostMesh\Config;

final class Crypto
{
    // X25519 key lengths in bytes
    public const X25519_PUBLIC_KEY_BYTES  = SODIUM_CRYPTO_BOX_PUBLICKEYBYTES;
    public const X25519_SECRET_KEY_BYTES  = SODIUM_CRYPTO_BOX_SECRETKEYBYTES;
    public const X25519_SHARED_SECRET_BYTES = SODIUM_CRYPTO_SCALARMULT_BYTES;

    // ChaCha20-Poly1305 IETF parameters
    public const CHACHA20_KEY_BYTES      = SODIUM_CRYPTO_AEAD_CHACHA20POLY1305_IETF_KEYBYTES;
    public const CHACHA20_NONCE_BYTES    = SODIUM_CRYPTO_AEAD_CHACHA20POLY1305_IETF_NPUBBYTES;
    public const CHACHA20_MAC_BYTES      = SODIUM_CRYPTO_AEAD_CHACHA20POLY1305_IETF_ABYTES;

    // Fixed packet size untuk traffic analysis resistance — semua paket terlihat sama dari luar
    public const FIXED_PACKET_SIZE       = 1024;

    // HKDF info strings — context binding per operasi, cegah key reuse lintas konteks
    public const HKDF_INFO_MESSAGE_KEY   = 'ghostmesh-v1-message-key';
    public const HKDF_INFO_HEADER_KEY    = 'ghostmesh-v1-header-key';
    public const HKDF_INFO_CHAIN_KEY     = 'ghostmesh-v1-chain-key';
    public const HKDF_INFO_ROOT_KEY      = 'ghostmesh-v1-root-key';

    // Proof-of-Work difficulty default
    public const POW_DIFFICULTY_BITS     = 16;

    // Nonce replay window — berapa lama nonce disimpan sebelum dianggap expired (detik)
    public const NONCE_REPLAY_WINDOW_TTL = 3600;

    // WebRTC signaling session TTL (detik)
    public const SIGNALING_SESSION_TTL   = 300;

    // Blind relay message TTL default (detik)
    public const BLIND_MESSAGE_TTL       = 3600;

    // Fingerprint length (hex chars) — 16 hex = 8 bytes = 64-bit pubkey fingerprint
    public const FINGERPRINT_HEX_LENGTH  = 16;

    private function __construct() {}
}