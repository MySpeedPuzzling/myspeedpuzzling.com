<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetUserBlocks;
use SpeedPuzzling\Web\Repository\ImportPartnerOptOutRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;

/**
 * Which registered player an import may put on a result of the importing player
 * (docs/features/data-import/people.md "Who may be added").
 *
 * **The add form's own rule**: PuzzlersGrouping::getPuzzlerFromUserInput() resolves a `#code` through
 * PlayerRepository::getByCode() - any player with that code, private or not, asked nothing; a code nobody has is a
 * guest of that name. Nothing else decides there.
 *
 * **On top, because nobody watches a bulk import**: a player with a block between them and the importing player -
 * in either direction - is never resolved, and neither is a player who took themself off this importing player's
 * imports (ImportPartnerOptOut). The answer is the same `null` as for a code nobody has, so the importing player
 * can not tell them apart.
 */
readonly final class ImportPartnerEligibility
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private GetUserBlocks $getUserBlocks,
        private ImportPartnerOptOutRepository $optOuts,
    ) {
    }

    public function byCode(Player $importer, string $code): null|Player
    {
        $code = trim($code, "# \t\n\r\0\x0B");

        if (preg_match('/^[a-zA-Z0-9]{3,8}$/', $code) !== 1) {
            return null;
        }

        try {
            return $this->allowed($importer, $this->playerRepository->getByCode($code));
        } catch (PlayerNotFound) {
            return null;
        }
    }

    public function byId(Player $importer, string $playerId): null|Player
    {
        if (Uuid::isValid($playerId) === false) {
            return null;
        }

        try {
            return $this->allowed($importer, $this->playerRepository->get($playerId));
        } catch (PlayerNotFound) {
            return null;
        }
    }

    /**
     * Of these players: the ones the importing player may not be put on a result with right now.
     *
     * @param list<string> $playerIds
     *
     * @return list<string>
     */
    public function notAllowedAmong(Player $importer, array $playerIds): array
    {
        $importerId = $importer->id->toString();

        return array_values(array_unique([
            ...array_map(strtolower(...), $this->getUserBlocks->blockedEitherWay($importerId, $playerIds)),
            ...$this->optOuts->among($importerId, $playerIds),
        ]));
    }

    /**
     * The player themself is returned: who is written is the importing player - the caller leaves them out.
     */
    private function allowed(Player $importer, Player $player): null|Player
    {
        if ($player->id->equals($importer->id)) {
            return $player;
        }

        return $this->notAllowedAmong($importer, [$player->id->toString()]) === [] ? $player : null;
    }
}
