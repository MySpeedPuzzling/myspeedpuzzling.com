<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\PartnerGoal;
use SpeedPuzzling\Web\Value\PartnerKind;

/**
 * The owner changes their open partner finder listing (docs/features/partner-finder.md). $coMemberIds null = keep the
 * listing's co-members as they are (the web form does not edit them yet).
 */
readonly final class EditPartnerListing
{
    /**
     * @param list<PartnerKind> $lookingFor
     * @param null|list<string> $coMemberIds player ids, null = unchanged
     * @param list<string> $strengths PartnerStrength values
     * @param list<string> $customStrengths as typed
     * @param list<string> $languages ISO 639-1 codes
     */
    public function __construct(
        public string $listingId,
        public string $playerId,
        public int $groupSize,
        public array $lookingFor,
        public null|int $needed,
        public null|array $coMemberIds,
        public null|PartnerGoal $goal,
        public array $strengths,
        public array $customStrengths,
        public array $languages,
        public null|string $note,
    ) {
    }
}
