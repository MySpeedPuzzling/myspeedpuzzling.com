<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * Somebody who took part in a result, as the current viewer may see them (docs/features/first-try-integrity.md).
 * A private player (PrivateProfileAccess) or one the viewer blocked (HiddenPlayers) is masked: neither name nor code -
 * in somebody else's pair/team result the viewer never typed that code themselves.
 */
readonly final class FirstTryPerson
{
    public function __construct(
        public null|string $playerId,
        public null|string $name,
        public null|string $code,
        public null|string $guestName,
        public bool $masked,
    ) {
    }

    public function isGuest(): bool
    {
        return $this->playerId === null;
    }

    public function is(string $playerId): bool
    {
        return $this->playerId !== null && strtolower($this->playerId) === strtolower($playerId);
    }

    /**
     * What to call them in a result the viewer took part in. Null when there is nothing the viewer may be shown.
     */
    public function label(): null|string
    {
        if ($this->isGuest()) {
            return $this->guestName;
        }

        if ($this->masked) {
            return null;
        }

        return $this->name ?? ($this->code !== null ? '#' . strtoupper($this->code) : null);
    }
}
