<?php
declare(strict_types=1);
namespace GhostMesh\Domain\Enums;

enum SignalingState: string
{
    case AWAITING_ANSWER = 'AWAITING_ANSWER';
    case PEER_CONNECTED = 'PEER_CONNECTED';
    case SESSION_TERMINATED = 'SESSION_TERMINATED';
}
