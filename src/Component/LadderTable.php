<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetFastestGroups;
use SpeedPuzzling\Web\Query\GetFastestPairs;
use SpeedPuzzling\Web\Query\GetFastestPlayers;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class LadderTable
{
    use DefaultActionTrait;

    #[LiveProp]
    public string $type = 'solo';

    #[LiveProp]
    public int $piecesCount = 500;

    #[LiveProp]
    public int $limit = 10;

    #[LiveProp]
    public null|string $countryCode = null;

    public function __construct(
        readonly private GetFastestPlayers $getFastestPlayers,
        readonly private GetFastestPairs $getFastestPairs,
        readonly private GetFastestGroups $getFastestGroups,
    ) {
    }

    /** @var null|list<SolvedPuzzle> */
    private null|array $items = null;

    /**
     * @return list<SolvedPuzzle>
     */
    public function getItems(): array
    {
        if ($this->items !== null) {
            return $this->items;
        }

        $country = $this->countryCode !== null
            ? CountryCode::fromCode($this->countryCode)
            : null;

        return $this->items = array_values(match ($this->type) {
            'solo' => $this->getFastestPlayers->perPiecesCount($this->piecesCount, $this->limit, $country),
            'pairs' => $this->getFastestPairs->perPiecesCount($this->piecesCount, $this->limit, $country),
            'groups' => $this->getFastestGroups->perPiecesCount($this->piecesCount, $this->limit, $country),
            default => [],
        });
    }

    /**
     * Gaps of every row like on the puzzle leaderboard: to the fastest time of this ladder and to the closest faster
     * time - the latter only when that is not the fastest one (the first gap says it). Rows come fastest first.
     *
     * @return list<array{leader: null|int, faster: null|int}>
     */
    public function getGaps(): array
    {
        $items = $this->getItems();
        $leaderTime = $items[0]->time ?? null;
        $gaps = [];
        $closestFaster = null;
        $previousTime = null;

        foreach ($items as $item) {
            $time = $item->time;

            // Tied rows share the closest faster time - only a slower time moves it on
            if ($previousTime !== null && $time !== null && $time > $previousTime) {
                $closestFaster = $previousTime;
            }

            $gaps[] = [
                'leader' => $time !== null && $leaderTime !== null && $time > $leaderTime ? $time - $leaderTime : null,
                'faster' => $time !== null && $closestFaster !== null && $closestFaster !== $leaderTime ? $time - $closestFaster : null,
            ];

            if ($time !== null && ($previousTime === null || $time > $previousTime)) {
                $previousTime = $time;
            }
        }

        return $gaps;
    }
}
