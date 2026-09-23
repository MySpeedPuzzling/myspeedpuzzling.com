<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;

/**
 * Turns whatever an operator has at hand - a player UUID, a player code, or an
 * e-mail address - into the player. Ops tooling only (console commands); the
 * web app always knows the id it is acting on.
 */
readonly final class ResolvePlayerByIdentifier
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private UserAccountRepository $userAccountRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function resolve(string $identifier): Player
    {
        $identifier = trim($identifier);

        if (Uuid::isValid($identifier)) {
            return $this->playerRepository->get($identifier);
        }

        if (str_contains($identifier, '@')) {
            // user_account.email is the single source of truth; a player without an
            // account row has no address and cannot be found this way
            $userAccount = $this->userAccountRepository->findByEmail($identifier);

            if ($userAccount === null) {
                throw new PlayerNotFound();
            }

            $player = $this->playerRepository->findByUserId($userAccount->userId);

            if ($player === null) {
                throw new PlayerNotFound();
            }

            return $player;
        }

        return $this->playerRepository->getByCode(ltrim($identifier, '#'));
    }
}
