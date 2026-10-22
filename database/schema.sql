CREATE DATABASE IF NOT EXISTS ghostmesh_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE ghostmesh_db;

CREATE TABLE IF NOT EXISTS ephemeral_nodes (
    id               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    node_fingerprint VARCHAR(64)      NOT NULL,
    software_version VARCHAR(32)      NOT NULL DEFAULT '1.0.0-zero-knowledge',
    is_active        TINYINT(1)       NOT NULL DEFAULT 1,
    registered_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_node_fingerprint (node_fingerprint),
    KEY idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS identity_prekey_bundles (
    id                        INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    identity_public_key_hex   VARCHAR(128)     NOT NULL,
    signed_prekey_hex         VARCHAR(128)     NOT NULL,
    prekey_signature_hex      VARCHAR(256)     NOT NULL,
    one_time_prekeys_count    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    registered_at             DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_identity_pub (identity_public_key_hex),
    KEY idx_prekey_count (one_time_prekeys_count)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blind_message_queues (
    id                          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    inbox_routing_token         VARCHAR(64)      NOT NULL,
    ephemeral_sender_pubkey_hex VARCHAR(128)     NOT NULL,
    encrypted_payload_ciphertext MEDIUMTEXT      NOT NULL,
    mac_tag_hex                 VARCHAR(64)      NOT NULL,
    burn_after_seconds          INT UNSIGNED     NOT NULL DEFAULT 3600,
    enqueued_at                 DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at                  DATETIME         NOT NULL,
    PRIMARY KEY (id),
    KEY idx_inbox_token   (inbox_routing_token),
    KEY idx_expires_at    (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS burn_on_read_receipts (
    id               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    message_queue_id BIGINT UNSIGNED  NOT NULL,
    receipt_hash     VARCHAR(64)      NOT NULL,
    burned_at        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_receipt_hash (receipt_hash),
    KEY idx_message_queue_id (message_queue_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blind_rendezvous_tokens (
    id                   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    rendezvous_token     VARCHAR(64)   NOT NULL,
    target_inbox_token   VARCHAR(64)   NOT NULL,
    created_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at           DATETIME      NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rendezvous_token (rendezvous_token),
    KEY idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webrtc_signaling_sessions (
    id                   BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    session_uuid         VARCHAR(48)      NOT NULL,
    initiator_hash       VARCHAR(64)      NOT NULL,
    peer_hash            VARCHAR(64)      NOT NULL,
    encrypted_sdp_offer  MEDIUMTEXT       NOT NULL,
    encrypted_sdp_answer MEDIUMTEXT,
    state                ENUM('AWAITING_ANSWER', 'PEER_CONNECTED', 'SESSION_TERMINATED') NOT NULL DEFAULT 'AWAITING_ANSWER',
    created_at           DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at           DATETIME         NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_session_uuid (session_uuid),
    KEY idx_initiator_hash (initiator_hash),
    KEY idx_peer_hash (peer_hash),
    KEY idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE EVENT IF NOT EXISTS ghostmesh_purge_expired_messages
    ON SCHEDULE EVERY 5 MINUTE
    DO
        DELETE FROM blind_message_queues WHERE expires_at < NOW();

CREATE EVENT IF NOT EXISTS ghostmesh_purge_expired_rendezvous
    ON SCHEDULE EVERY 10 MINUTE
    DO
        DELETE FROM blind_rendezvous_tokens WHERE expires_at < NOW();

CREATE EVENT IF NOT EXISTS ghostmesh_purge_expired_signaling
    ON SCHEDULE EVERY 5 MINUTE
    DO
        DELETE FROM webrtc_signaling_sessions WHERE expires_at < NOW();
