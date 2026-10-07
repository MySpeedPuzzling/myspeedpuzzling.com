<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\ParticipantImportRows;

/**
 * The organiser confirmed the import preview. The handler plans again under the event's lock, inside its transaction,
 * and writes only when the plan's fingerprint is still the one the preview showed (design doc D8) - two imports of
 * one event never overlap.
 */
readonly final class ApplyParticipantImport implements SerializedByLock
{
    public function __construct(
        public string $competitionId,
        public ParticipantImportRows $rows,
        /** ParticipantImportMode value */
        public string $mode,
        public string $expectedFingerprint,
    ) {
    }

    public function lockKey(): string
    {
        return 'participant-import-' . strtolower($this->competitionId);
    }
}
