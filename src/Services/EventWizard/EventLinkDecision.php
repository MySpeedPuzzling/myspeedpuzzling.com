<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventWizard;

/**
 * EventLinkRule::decide() - linked now, waits until the event is public, or refused
 */
enum EventLinkDecision
{
    case Now;
    case Waits;
    case Refused;
}
