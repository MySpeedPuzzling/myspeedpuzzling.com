<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One row of their `Jugadores` table, as returned by `users_pr.php?accion=wjpf_user`.
 */
readonly final class WjpfUser
{
    public function __construct(
        /** Their `IdJugador`. */
        public string $idJugador,
        /** Their `NombreURL` - profile slug on worldjigsawpuzzle.org. */
        public null|string $nombreUrl,
        /**
         * Their `MySpeedPuzzlingId` column *as it was before this call*. Their endpoint
         * echoes the row and only then runs its UPDATE, so this never reflects a write we
         * just triggered - an empty value means the column was free for us to claim.
         */
        public null|string $mySpeedPuzzlingId,
        /** @var array<string, mixed> */
        public array $raw,
    ) {
    }

    /** Their column was empty, so a claim sent alongside this call was stored. */
    public function isUnclaimed(): bool
    {
        return $this->mySpeedPuzzlingId === null || trim($this->mySpeedPuzzlingId) === '';
    }

    public function isClaimedBy(string $playerId): bool
    {
        if ($this->mySpeedPuzzlingId === null) {
            return false;
        }

        return strcasecmp(trim($this->mySpeedPuzzlingId), $playerId) === 0;
    }
}
