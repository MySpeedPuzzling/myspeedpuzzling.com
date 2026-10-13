<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\ParticipantsSheet\ParticipantsSheetPlayer;

/**
 * One participant on the organiser's personal links page (docs/features/online-competitions/README.md §5.4,
 * GetOnlineLinks) - not removed. `token` is the secret their personal link carries (null: none yet - a waitlisted
 * participant gets none). The linked profile is shown the way the participants sheet shows it (withheld when the viewer
 * blocks the player or the profile is private to them).
 */
readonly final class OnlineLinkRow
{
    public function __construct(
        public string $participantId,
        public string $name,
        // CountryCode case name (lower case) or null
        public null|string $country,
        public bool $waitlisted,
        public null|string $token,
        public null|DateTimeImmutable $tokenCreatedAt,
        public null|ParticipantsSheetPlayer $player,
    ) {
    }

    /**
     * Going (not on the waitlist) and still without a link - GenerateOnlineTokens gives them one
     */
    public function lacksLink(): bool
    {
        return $this->waitlisted === false && $this->token === null;
    }
}
