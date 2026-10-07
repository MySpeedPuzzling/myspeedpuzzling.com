<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;

/**
 * An uploaded participant list waiting for the organiser's confirmation (ParticipantImportStash).
 */
readonly final class StashedParticipantImport
{
    public function __construct(
        public string $token,
        public string $fileName,
        public ParticipantFileFormat $format,
        public DateTimeImmutable $storedAt,
    ) {
    }
}
