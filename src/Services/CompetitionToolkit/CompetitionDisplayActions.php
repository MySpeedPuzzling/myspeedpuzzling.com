<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

/**
 * The CSRF token of a display's row buttons (duplicate, new link, delete) - one per display, session-backed: these are
 * organiser pages of a signed-in player.
 */
final readonly class CompetitionDisplayActions
{
    public static function csrfTokenId(string $displayId): string
    {
        return 'competition_display_' . strtolower($displayId);
    }
}
