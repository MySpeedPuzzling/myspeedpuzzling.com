<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\MspRating;

use DateTimeZone;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetRatingLadderThisWeek;
use SpeedPuzzling\Web\Results\RatingLadderSpotlight;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * "This week on the ladder" (docs/features/msp-rating-history.md): the candidates are the same for everybody and
 * change with the 15-minute rating batch, so they are cached; each viewer gets the first of each tile they may see
 * (HiddenPlayers), one player in one tile only. The places are the world ladder's without anybody's blocks.
 */
readonly final class RatingLadderThisWeek
{
    private const int CACHE_TTL = 900;

    public function __construct(
        private GetRatingLadderThisWeek $getRatingLadderThisWeek,
        private HiddenPlayers $hiddenPlayers,
        private CacheInterface $ratingLadderThisWeekCache,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<RatingLadderSpotlight> at most one per kind: climber, new entry, best ever
     */
    public function spotlights(int $piecesCount): array
    {
        $now = $this->clock->now();
        $day = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');

        /** @var list<RatingLadderSpotlight> $candidates */
        $candidates = $this->ratingLadderThisWeekCache->get(
            "rating_ladder_this_week_{$piecesCount}_{$day}",
            function (ItemInterface $item) use ($piecesCount, $now): array {
                $item->expiresAfter(self::CACHE_TTL);

                return $this->getRatingLadderThisWeek->candidates($piecesCount, $now);
            },
        );

        $tiles = [];
        $shown = [];

        foreach ($candidates as $candidate) {
            if (isset($tiles[$candidate->kind]) || isset($shown[$candidate->playerId]) || $this->hiddenPlayers->isHidden($candidate->playerId)) {
                continue;
            }

            $tiles[$candidate->kind] = $candidate;
            $shown[$candidate->playerId] = true;
        }

        return array_values($tiles);
    }
}
