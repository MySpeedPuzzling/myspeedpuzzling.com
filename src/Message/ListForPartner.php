<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;
use SpeedPuzzling\Web\Value\PartnerGoal;
use SpeedPuzzling\Web\Value\PartnerKind;

/**
 * "List me" in the partner finder (docs/features/partner-finder.md, D1-D3). $listingId comes from the form (UUIDv7 made
 * when it was shown), so a resent form is a no-op. Listing marks the player going on a plain event in the same step
 * (PartnerFinderAttendance), so it takes turns with every other write to the event's participants.
 */
readonly final class ListForPartner implements SerializedByLock
{
    /**
     * @param list<PartnerKind> $lookingFor
     * @param list<string> $coMemberIds player ids
     * @param list<string> $strengths PartnerStrength values
     * @param list<string> $customStrengths as typed
     * @param list<string> $languages ISO 639-1 codes
     */
    public function __construct(
        public string $listingId,
        public string $competitionId,
        public string $playerId,
        public int $groupSize,
        public array $lookingFor,
        public null|int $needed,
        public array $coMemberIds,
        public null|PartnerGoal $goal,
        public array $strengths,
        public array $customStrengths,
        public array $languages,
        public null|string $note,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
