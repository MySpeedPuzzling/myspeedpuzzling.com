<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * One person of a pair/team (puzzling_team_member) as the viewer may see them: a guest by the name typed in, a
 * registered player by name - unless the player is private and has not let the viewer see them (`hidden`: shown as a
 * hidden puzzler, name and code null) - and `blocked` when the viewer blocks them (the synergy leaves such a pair/team
 * out, docs/features/insights/computation.md §4c.1).
 */
readonly final class GroupResultMember
{
    public function __construct(
        public null|string $playerId,
        public null|string $guestName,
        public null|string $name,
        public null|string $code,
        public bool $hidden,
        public bool $blocked,
    ) {
    }

    public function isGuest(): bool
    {
        return $this->playerId === null;
    }
}
