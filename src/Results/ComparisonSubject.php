<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\ComparisonKind;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;
use SpeedPuzzling\Web\Value\CountryCode;

/**
 * One subject of a line-up as the current viewer may see it (GetComparisonSubjects, docs/features/player-comparison.md
 * "Visibility"). An unavailable subject keeps only its ref (and kind when known): no name, avatar or members - the UI
 * shows a neutral "No longer available" chip, the results never include it.
 */
readonly final class ComparisonSubject
{
    /**
     * @param list<PuzzlingTeamMemberView> $members pairs/teams only, in the team's member order; private members masked
     */
    public function __construct(
        public ComparisonSubjectRef $ref,
        // Null only for a pair/team that does not exist (any more)
        public null|ComparisonKind $kind,
        public bool $isAvailable,
        // Players: the viewer's own ref
        public bool $isViewer = false,
        public null|string $playerName = null,
        public null|string $playerCode = null,
        public null|string $playerAvatar = null,
        public null|CountryCode $playerCountry = null,
        // Pairs/teams
        public null|string $teamName = null,
        public null|int $teamSize = null,
        public array $members = [],
        // "You're in it"
        public bool $includesViewer = false,
    ) {
    }

    public static function unavailable(ComparisonSubjectRef $ref, null|ComparisonKind $kind): self
    {
        return new self(ref: $ref, kind: $kind, isAvailable: false);
    }

    public function isTeam(): bool
    {
        return $this->ref->isPlayer() === false;
    }

    /**
     * Player id for the avatar tint (`_player_avatar.html.twig` picks the colour by its last hex digit).
     */
    public function playerId(): null|string
    {
        return $this->ref->isPlayer() ? $this->ref->id : null;
    }

    /**
     * The subject "you" are, for the default highlight pair: your own player ref, or a pair/team you are in.
     */
    public function isSelf(): bool
    {
        return $this->isAvailable && ($this->isViewer || $this->includesViewer);
    }
}
