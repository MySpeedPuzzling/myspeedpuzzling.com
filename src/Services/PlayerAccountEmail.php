<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Repository\UserAccountRepository;

/**
 * The one place ORM-side code asks "where do I reach this player": `user_account.email`
 * is the single source of truth for a player's address (the sign-in address, where
 * sign-in links and password resets go). `player.email` is a mirror kept only for the
 * blue-green rollout and is dropped in release 2 - nothing may read it.
 *
 * A player without an account row (a couple of Auth0-era leftovers) has no e-mail.
 * Raw SQL in src/Query joins user_account the same way instead of calling this.
 */
// Not final on purpose: unit tests of mail-sending handlers stub it, the way they stub repositories
readonly class PlayerAccountEmail
{
    public function __construct(
        private UserAccountRepository $userAccountRepository,
    ) {
    }

    public function ofPlayer(Player $player): null|string
    {
        if ($player->userId === null) {
            return null;
        }

        return $this->userAccountRepository->findByUserId($player->userId)?->email;
    }
}
