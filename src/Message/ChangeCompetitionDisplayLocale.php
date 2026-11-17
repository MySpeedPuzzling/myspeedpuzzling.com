<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The language of the display's own texts ("Round", "Time's up", ...) - the URL has no locale (D §2 "URL").
 */
readonly final class ChangeCompetitionDisplayLocale
{
    public function __construct(
        public string $displayId,
        public string $locale,
    ) {
    }
}
