<?php
declare(strict_types=1);
namespace GhostMesh\Domain\Enums;

enum DeliveryAckStatus: string
{
    case BLIND_ENQUEUED = 'BLIND_ENQUEUED';
    case BLIND_RELAYED = 'BLIND_RELAYED';
    case BURNED_DESTROYED = 'BURNED_DESTROYED';
}