<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * Somebody the player has lent to or borrowed from before - a suggestion for
 * the multiscan person picker.
 */
readonly final class LendBorrowCounterparty
{
    /**
     * @param 'lend'|'borrow' $role `lend` = I handed puzzles to them, `borrow` = they handed puzzles to me
     * @param string $value What the person input takes: `#code` for a registered player, the plain name otherwise
     */
    public function __construct(
        public string $role,
        public string $value,
        public string $label,
        public null|string $code,
        public null|string $avatar,
        public int $times,
    ) {
    }

    public function isRegistered(): bool
    {
        return $this->code !== null;
    }
}
