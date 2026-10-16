<?php
declare(strict_types=1);
namespace GhostMesh\Domain\Enums;

enum MessageType: string
{
    case DIRECT_E2EE = 'DIRECT_E2EE';
    case EPHEMERAL_ROOM_BROADCAST ='EPHEMERAL_ROOM_BROADCAST';
    case WEBRTC_SIGNAL_OFFER ='WEBRTC_SIGNAL_OFFER';
    case WEBRTC_SIGNAL_ANSWER = 'WEBRTC_SIGNAL_ANSWER';
    case WEBRTC_ICE_CANDIDATE = 'WEBRTC_ICE_CANDIDATE';
    case BURN_RECEIPT = 'BURN_RECEIPT';
}