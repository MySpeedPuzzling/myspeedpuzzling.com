<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\CompetitorPageSettings;

/**
 * The organiser's competitor pages & QR settings of one competition (docs/features/competitions-management/toolkit/
 * B-qr-competitor-page.md §2), saved from the competition toolkit hub. Touches only the competition row, so it needs
 * no participants lock.
 */
readonly final class ChangeCompetitorPageSettings
{
    public function __construct(
        public string $competitionId,
        public CompetitorPageSettings $settings,
    ) {
    }
}
