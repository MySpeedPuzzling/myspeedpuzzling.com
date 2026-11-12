<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;

readonly final class AddEventResultsSheet
{
    public function __construct(
        public UuidInterface $sheetId,
        public string $spreadsheetId,
        public string $organizationId,
        public string $actingPlayerId,
        public string $timezone,
        public string $countryCode,
    ) {
    }
}
