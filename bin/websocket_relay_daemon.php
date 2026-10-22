#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use GhostMesh\Security\GhostMeshSecurityFilter;
use GhostMesh\Service\ProofOfWorkRateLimiterService;

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------
$host            = getenv('WS_HOST')           ?: '0.0.0.0';
$port            = (int) (getenv('WS_PORT')    ?: 9000);
$maxConnections  = (int) (getenv('WS_MAX_CONN') ?: 500);
$powDifficulty   = (int) (getenv('POW_BITS')   ?: 12);
$signalingTtl    = (int) (getenv('SIGNAL_TTL') ?: 300);
$messageTtl      = (int) (getenv('MSG_TTL')    ?: 3600);

// ---------------------------------------------------------------------------
// State — semua in-memory, zero log ke disk
// ---------------------------------------------------------------------------
$clients         = [];   // fd => ['socket', 'routing_token', 'pow_verified', 'connected_at']
$messageQueues   = [];   // routing_token => [envelope payload string, ...]
$signalingPool   = [];   // session_uuid => session array
$seenNonces      = [];   // nonce_hex => timestamp
$powChallenges   = [];   // fd => challenge array

$secFilter       = new GhostMeshSecurityFilter();
$powService      = new ProofOfWorkRateLimiterService();

// ---------------------------------------------------------------------------
// Helper functions
// ---------------------------------------------------------------------------
function ws_encode(string $payload): string
{
    $len    = strlen($payload);
    $header = chr(0x81);

    if ($len <= 125) {
        $header .= chr($len);
    } elseif ($len <= 65535) {
        $header .= chr(126) . pack('n', $len);
    } else {
        $header .= chr(127) . pack('J', $len);
    }

    return $header . $payload;
}

function ws_decode(string $data): ?string
{
    if (strlen($data) < 2) {
        return null;
    }

    $masked     = (ord($data[1]) & 0x80) !== 0;
    $payloadLen = ord($data[1]) & 0x7F;
    $offset     = 2;

    if ($payloadLen === 126) {
        if (strlen($data) < 4) {
            return null;
        }
        $payloadLen = unpack('n', substr($data, 2, 2))[1];
        $offset     = 4;
    } elseif ($payloadLen === 127) {
        if (strlen($data) < 10) {
            return null;
        }
        $payloadLen = unpack('J', substr($data, 2, 8))[1];
        $offset     = 10;
    }

    if ($masked) {
        if (strlen($data) < $offset + 4 + $payloadLen) {
            return null;
        }
        $mask    = substr($data, $offset, 4);
        $offset += 4;
        $payload = substr($data, $offset, $payloadLen);
        $result  = '';
        for ($i = 0; $i < $payloadLen; $i++) {
            $result .= chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
        }
        return $result;
    }

    return substr($data, $offset, $payloadLen);
}

function ws_handshake(string $request): string
{
    preg_match('/Sec-WebSocket-Key:\s*([^\r\n]+)/i', $request, $matches);
    $key    = trim($matches[1] ?? '');
    $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

    return "HTTP/1.1 101 Switching Protocols\r\n"
         . "Upgrade: websocket\r\n"
         . "Connection: Upgrade\r\n"
         . "Sec-WebSocket-Accept: {$accept}\r\n\r\n";
}

function send_to(mixed $socket, array $payload): void
{
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    @socket_write($socket, ws_encode($json));
}

function close_client(mixed $socket, int $fd, array &$clients, array &$powChallenges): void
{
    @socket_close($socket);
    unset($clients[$fd], $powChallenges[$fd]);
}

function purge_expired_nonces(array &$seenNonces, int $ttl): void
{
    $cutoff = time() - $ttl;
    foreach ($seenNonces as $nonce => $ts) {
        if ($ts < $cutoff) {
            unset($seenNonces[$nonce]);
        }
    }
}

function purge_expired_messages(array &$messageQueues): void
{
    foreach ($messageQueues as $token => $messages) {
        $alive = [];
        foreach ($messages as $msg) {
            if (($msg['expires_at'] ?? 0) > time()) {
                $alive[] = $msg;
            }
        }
        if (empty($alive)) {
            unset($messageQueues[$token]);
        } else {
            $messageQueues[$token] = $alive;
        }
    }
}

function purge_expired_signaling(array &$signalingPool): void
{
    $now = time();
    foreach ($signalingPool as $uuid => $session) {
        if (($session['expires_at'] ?? 0) <= $now) {
            unset($signalingPool[$uuid]);
        }
    }
}

// ---------------------------------------------------------------------------
// Handler — proses pesan per tipe
// ---------------------------------------------------------------------------
function handle_message(
    int    $fd,
    mixed  $socket,
    array  $msg,
    array  &$clients,
    array  &$messageQueues,
    array  &$signalingPool,
    array  &$seenNonces,
    array  &$powChallenges,
    GhostMeshSecurityFilter   $secFilter,
    ProofOfWorkRateLimiterService $powService,
    int    $powDifficulty,
    int    $signalingTtl,
    int    $messageTtl
): void {
    $type = $msg['type'] ?? '';

    // PoW belum diverifikasi — hanya boleh kirim POW_SOLUTION
    if (!($clients[$fd]['pow_verified'] ?? false) && $type !== 'POW_SOLUTION') {
        send_to($socket, ['type' => 'ERROR', 'code' => 'POW_REQUIRED']);
        return;
    }

    switch ($type) {
        case 'POW_SOLUTION':
            $challenge = $powChallenges[$fd] ?? null;
            if ($challenge === null) {
                send_to($socket, ['type' => 'ERROR', 'code' => 'NO_CHALLENGE']);
                return;
            }

            $nonce = (string) ($msg['nonce'] ?? '');
            if (!$powService->verifyProofOfWork($challenge['challenge_seed'], $nonce, $powDifficulty)) {
                send_to($socket, ['type' => 'ERROR', 'code' => 'POW_INVALID']);
                close_client($socket, $fd, $clients, $powChallenges);
                return;
            }

            $clients[$fd]['pow_verified'] = true;
            unset($powChallenges[$fd]);
            send_to($socket, ['type' => 'POW_ACCEPTED']);
            break;

        case 'REGISTER_INBOX':
            $token = $msg['routing_token'] ?? '';
            if (empty($token) || strlen($token) < 16) {
                send_to($socket, ['type' => 'ERROR', 'code' => 'INVALID_TOKEN']);
                return;
            }
            $clients[$fd]['routing_token'] = $token;
            send_to($socket, ['type' => 'INBOX_REGISTERED', 'routing_token' => $token]);

            if (!empty($messageQueues[$token])) {
                foreach ($messageQueues[$token] as $queued) {
                    send_to($socket, ['type' => 'INCOMING_MESSAGE', 'envelope' => $queued['envelope']]);
                }
                unset($messageQueues[$token]);
            }
            break;

        case 'SEND_MESSAGE':
            $nonceHex = $msg['nonce'] ?? '';
            if (empty($nonceHex)) {
                send_to($socket, ['type' => 'ERROR', 'code' => 'NONCE_MISSING']);
                return;
            }

            try {
                $secFilter->assertNonceFreshness($nonceHex, $seenNonces);
            } catch (RuntimeException) {
                send_to($socket, ['type' => 'ERROR', 'code' => 'REPLAY_ATTACK_DETECTED']);
                return;
            }

            $targetToken = $msg['routing_token'] ?? '';
            $envelope    = $msg['envelope']      ?? null;

            if (empty($targetToken) || $envelope === null) {
                send_to($socket, ['type' => 'ERROR', 'code' => 'INVALID_PAYLOAD']);
                return;
            }

            $entry = [
                'envelope'   => $envelope,
                'enqueued_at' => time(),
                'expires_at'  => time() + $messageTtl,
            ];

            // Coba deliver langsung ke penerima kalau online
            $delivered = false;
            foreach ($clients as $peerFd => $peer) {
                if (($peer['routing_token'] ?? '') === $targetToken && $peerFd !== $fd) {
                    send_to($peer['socket'], ['type' => 'INCOMING_MESSAGE', 'envelope' => $envelope]);
                    $delivered = true;
                    break;
                }
            }

            if (!$delivered) {
                $messageQueues[$targetToken][] = $entry;
            }

            send_to($socket, ['type' => 'MESSAGE_ENQUEUED', 'delivered' => $delivered]);
            break;

        case 'POLL_INBOX':
            $token = $clients[$fd]['routing_token'] ?? '';
            if (empty($token)) {
                send_to($socket, ['type' => 'ERROR', 'code' => 'NOT_REGISTERED']);
                return;
            }

            $pending = $messageQueues[$token] ?? [];
            if (!empty($pending)) {
                foreach ($pending as $queued) {
                    send_to($socket, ['type' => 'INCOMING_MESSAGE', 'envelope' => $queued['envelope']]);
                }
                unset($messageQueues[$token]);
            }

            send_to($socket, ['type' => 'POLL_DONE', 'count' => count($pending)]);
            break;

        case 'BURN_ACK':
            send_to($socket, ['type' => 'BURN_CONFIRMED']);
            break;

        case 'WEBRTC_OFFER':
            $sessionUuid   = 'WTC-' . bin2hex(random_bytes(16));
            $now           = time();
            $initiatorHash = $msg['initiator_hash'] ?? '';
            $peerHash      = $msg['peer_hash']       ?? '';
            $offer         = $msg['encrypted_offer']  ?? '';

            if (empty($initiatorHash) || empty($peerHash) || empty($offer)) {
                send_to($socket, ['type' => 'ERROR', 'code' => 'INVALID_WEBRTC_OFFER']);
                return;
            }

            $signalingPool[$sessionUuid] = [
                'session_uuid'    => $sessionUuid,
                'initiator_hash'  => $initiatorHash,
                'peer_hash'       => $peerHash,
                'encrypted_offer' => $offer,
                'state'           => 'AWAITING_ANSWER',
                'created_at'      => $now,
                'expires_at'      => $now + $signalingTtl,
            ];

            // Forward offer ke peer yang online
            foreach ($clients as $peerFd => $peer) {
                if (($peer['routing_token'] ?? '') === $peerHash) {
                    send_to($peer['socket'], [
                        'type'           => 'WEBRTC_OFFER',
                        'session_uuid'   => $sessionUuid,
                        'encrypted_offer' => $offer,
                    ]);
                    break;
                }
            }

            send_to($socket, ['type' => 'WEBRTC_OFFER_RELAYED', 'session_uuid' => $sessionUuid]);
            break;

        case 'WEBRTC_ANSWER':
            $sessionUuid = $msg['session_uuid']      ?? '';
            $answer      = $msg['encrypted_answer']  ?? '';

            $session = $signalingPool[$sessionUuid] ?? null;
            if ($session === null) {
                send_to($socket, ['type' => 'ERROR', 'code' => 'SESSION_NOT_FOUND']);
                return;
            }

            if ($session['state'] !== 'AWAITING_ANSWER') {
                send_to($socket, ['type' => 'ERROR', 'code' => 'SESSION_INVALID_STATE']);
                return;
            }

            $signalingPool[$sessionUuid]['state']            = 'PEER_CONNECTED';
            $signalingPool[$sessionUuid]['encrypted_answer'] = $answer;

            // Forward answer ke initiator
            $initiatorHash = $session['initiator_hash'];
            foreach ($clients as $peerFd => $peer) {
                if (($peer['routing_token'] ?? '') === $initiatorHash) {
                    send_to($peer['socket'], [
                        'type'             => 'WEBRTC_ANSWER',
                        'session_uuid'     => $sessionUuid,
                        'encrypted_answer' => $answer,
                    ]);
                    break;
                }
            }

            send_to($socket, ['type' => 'WEBRTC_ANSWER_RELAYED']);
            break;

        case 'WEBRTC_ICE':
            $targetHash = $msg['target_hash'] ?? '';
            $candidate  = $msg['candidate']   ?? '';

            if (empty($targetHash) || empty($candidate)) {
                send_to($socket, ['type' => 'ERROR', 'code' => 'INVALID_ICE']);
                return;
            }

            foreach ($clients as $peerFd => $peer) {
                if (($peer['routing_token'] ?? '') === $targetHash) {
                    send_to($peer['socket'], [
                        'type'      => 'WEBRTC_ICE',
                        'candidate' => $candidate,
                    ]);
                    break;
                }
            }
            break;

        default:
            send_to($socket, ['type' => 'ERROR', 'code' => 'UNKNOWN_MESSAGE_TYPE']);
    }
}

// ---------------------------------------------------------------------------
// Bootstrap server socket
// ---------------------------------------------------------------------------
$serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if ($serverSocket === false) {
    fprintf(STDERR, "socket_create gagal: %s\n", socket_strerror(socket_last_error()));
    exit(1);
}

socket_set_option($serverSocket, SOL_SOCKET, SO_REUSEADDR, 1);

if (!socket_bind($serverSocket, $host, $port)) {
    fprintf(STDERR, "socket_bind gagal: %s\n", socket_strerror(socket_last_error($serverSocket)));
    exit(1);
}

if (!socket_listen($serverSocket, 128)) {
    fprintf(STDERR, "socket_listen gagal: %s\n", socket_strerror(socket_last_error($serverSocket)));
    exit(1);
}

socket_set_nonblock($serverSocket);

fprintf(STDOUT, "GhostMesh WebSocket relay daemon berjalan di ws://%s:%d\n", $host, $port);

$handshakeDone = [];
$lastCleanup   = time();

// ---------------------------------------------------------------------------
// Main event loop
// ---------------------------------------------------------------------------
while (true) {
    $read   = [$serverSocket];
    $write  = null;
    $except = null;

    foreach ($clients as $fd => $client) {
        $read[] = $client['socket'];
    }

    if (@socket_select($read, $write, $except, 1) === false) {
        continue;
    }

    // Periodic cleanup
    if (time() - $lastCleanup >= 60) {
        purge_expired_nonces($seenNonces, 3600);
        purge_expired_messages($messageQueues);
        purge_expired_signaling($signalingPool);
        $lastCleanup = time();
    }

    foreach ($read as $readSocket) {
        if ($readSocket === $serverSocket) {
            $client = @socket_accept($serverSocket);
            if ($client === false) {
                continue;
            }

            if (count($clients) >= $maxConnections) {
                @socket_close($client);
                continue;
            }

            $fd = spl_object_id($client);
            socket_set_nonblock($client);
            $clients[$fd] = [
                'socket'       => $client,
                'routing_token' => '',
                'pow_verified'  => false,
                'connected_at'  => time(),
            ];
            $handshakeDone[$fd] = false;
            continue;
        }

        $fd = spl_object_id($readSocket);

        if (!isset($clients[$fd])) {
            continue;
        }

        $data = @socket_read($readSocket, 65536);

        if ($data === false) {
            $err = socket_last_error($readSocket);
            if ($err === SOCKET_EAGAIN || $err === SOCKET_EWOULDBLOCK) {
                continue;
            }
            close_client($readSocket, $fd, $clients, $powChallenges);
            unset($handshakeDone[$fd]);
            continue;
        }

        if ($data === '') {
            close_client($readSocket, $fd, $clients, $powChallenges);
            unset($handshakeDone[$fd]);
            continue;
        }

        // WebSocket handshake
        if (!($handshakeDone[$fd] ?? false)) {
            if (stripos($data, 'Upgrade') !== false && stripos($data, 'websocket') !== false) {
                $response = ws_handshake($data);
                @socket_write($readSocket, $response);
                $handshakeDone[$fd] = true;

                // Issue PoW challenge immediately on connect
                $challenge              = $powService->issueChallenge($powDifficulty);
                $powChallenges[$fd]     = $challenge;
                send_to($readSocket, [
                    'type'            => 'POW_CHALLENGE',
                    'challenge_seed'  => $challenge['challenge_seed'],
                    'difficulty_bits' => $challenge['difficulty_bits'],
                ]);
            }
            continue;
        }

        // Decode WebSocket frame
        $payload = ws_decode($data);
        if ($payload === null) {
            continue;
        }

        try {
            $msg = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            send_to($readSocket, ['type' => 'ERROR', 'code' => 'INVALID_JSON']);
            continue;
        }

        $clients[$fd]['socket'] = $readSocket;

        handle_message(
            $fd, $readSocket, $msg,
            $clients, $messageQueues, $signalingPool,
            $seenNonces, $powChallenges,
            $secFilter, $powService,
            $powDifficulty, $signalingTtl, $messageTtl
        );
    }
}