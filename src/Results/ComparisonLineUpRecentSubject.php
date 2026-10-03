<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\ComparisonKind;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;
use SpeedPuzzling\Web\Value\CountryCode;

/**
 * A mini avatar of the comparison launcher (docs/features/player-comparison.md, D2): one of the newest subjects of the
 * viewer's line-ups. A pair/team is a people icon - no identity at all. A player the viewer may not see (one they
 * blocked, or private and not revealed to them) is masked: a generic icon, name/avatar/country are null.
 */
readonly final class ComparisonLineUpRecentSubject
{
    public function __construct(
        public ComparisonSubjectRef $ref,
        public ComparisonKind $kind,
        public bool $isMasked = false,
        // The display name - the player's name, or their #CODE without one
        public null|string $name = null,
        public null|string $avatar = null,
        public null|CountryCode $countryCode = null,
    ) {
    }

    public function isTeam(): bool
    {
        return $this->ref->isPlayer() === false;
    }

    /**
     * The letter on an avatar without a photo or a flag (its tint follows the last digit of the player id, `ref.id`).
     */
    public function initial(): null|string
    {
        if ($this->name === null) {
            return null;
        }

        $letter = mb_substr(ltrim(trim($this->name), '#'), 0, 1);

        return $letter === '' ? null : mb_strtoupper($letter);
    }
}
