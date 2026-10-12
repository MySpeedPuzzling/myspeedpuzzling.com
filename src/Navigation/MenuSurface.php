<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * The two orders of the one Menu (D21): the phone panel (also the overlay and the fallback page, which follow the
 * phone rows) and the docked column / rail target on desktop (navigation README §4.3).
 */
enum MenuSurface: string
{
    case Phone = 'phone';
    case Dock = 'dock';
}
