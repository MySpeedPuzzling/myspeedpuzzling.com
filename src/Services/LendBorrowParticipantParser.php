<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Exceptions\CannotLendToSelf;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\LendBorrowParticipant;

/**
 * The person on the other side of a lend: `#code` of a registered player, or
 * any other text as the name of somebody without an account. The one place for
 * that convention - the lend / borrow / pass forms and the multiscan tray share it.
 */
readonly final class LendBorrowParticipantParser
{
    public function __construct(
        private PlayerRepository $playerRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws CannotLendToSelf
     */
    public function parse(string $input, string $actingPlayerId): LendBorrowParticipant
    {
        $cleaned = trim($input, "# \t\n\r\0");

        if (str_starts_with(trim($input), '#')) {
            $player = $this->playerRepository->getByCode($cleaned);

            if ($player->id->toString() === $actingPlayerId) {
                throw new CannotLendToSelf();
            }

            return new LendBorrowParticipant($player->id->toString(), null, $player->name ?? $player->code);
        }

        return new LendBorrowParticipant(null, $cleaned, $cleaned);
    }
}
