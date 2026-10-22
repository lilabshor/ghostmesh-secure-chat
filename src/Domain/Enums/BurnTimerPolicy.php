<?php
declare(strict_types=1);

namespace GhostMesh\Domain\Enums;

enum BurnTimerPolicy: string
{
    case THIRTY_SECONDS = 'BURN_30S';
    case FIVE_MINUTES   = 'BURN_5M';
    case ONE_HOUR       = 'BURN_1H';
    case BURN_ON_READ   = 'BURN_ON_READ';
    case NEVER          = 'NEVER';
}
