<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Results\SolvedPuzzleDetail;

/**
 * What a result share card shows, as a short hash: the card is kept (ResultImageCache) and linked (the recap page's
 * `?v=`) under it, so an edited time, a renamed player or puzzle, a new photo or a privacy switch gets a card of its
 * own at once - never the one kept from before. The rank line is left out on purpose: it is drawn when the card is
 * first drawn and stays while the card is kept (asking for it would cost the ranking query on every request).
 */
readonly final class ResultImageVersion
{
    /**
     * Bump when the card is drawn differently - every card is then drawn again
     */
    private const int DRAWING = 2;

    public function __construct(
        private PlayerRepository $playerRepository,
    ) {
    }

    /**
     * The card follows the player's own setting, never who is looking (GetPlayerProfile masks it per viewer)
     */
    public function isPrivateProfile(string $playerId): bool
    {
        return $this->playerRepository->get($playerId)->isPrivate;
    }

    public function of(SolvedPuzzleDetail $time, PlayerProfile $player): string
    {
        $isPrivateProfile = $this->isPrivateProfile($time->playerId);

        $shown = [
            self::DRAWING,
            $time->timeId,
            $time->puzzleName,
            $time->manufacturerName,
            $time->piecesCount,
            $time->time,
            $time->players === null ? null : count($time->players),
            $time->finishedPuzzlePhoto ?? $time->puzzleImage,
            $time->puzzleImageHidden,
            $isPrivateProfile,
            $isPrivateProfile ? null : $player->playerName,
            $player->code,
        ];

        return substr(hash('xxh128', json_encode($shown, JSON_THROW_ON_ERROR)), 0, 16);
    }
}
