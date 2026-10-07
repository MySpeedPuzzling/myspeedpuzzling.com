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
        /** The name of the file as uploaded (shown on the preview) */
        public string $fileName,
        public ParticipantFileFormat $format,
        public DateTimeImmutable $storedAt,
        /** Set once the import was confirmed - a second confirm answers "Already imported" */
        public null|DateTimeImmutable $appliedAt,
        public string $uploadedByPlayerId,
        /** CSV: the encoding + separator "Automatic" picks (shown next to the selects), null for .xlsx */
        public null|ParticipantFileOptions $detectedOptions = null,
    ) {
    }

    public function isApplied(): bool
    {
        return $this->appliedAt !== null;
    }
}
