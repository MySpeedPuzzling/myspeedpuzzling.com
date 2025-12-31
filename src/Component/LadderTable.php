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

    /**
     * @return array<SolvedPuzzle>
     */
    public function getItems(): array
    {
        $country = $this->countryCode !== null
            ? CountryCode::fromCode($this->countryCode)
            : null;

        return match ($this->type) {
            'solo' => $this->getFastestPlayers->perPiecesCount($this->piecesCount, $this->limit, $country),
            'pairs' => $this->getFastestPairs->perPiecesCount($this->piecesCount, $this->limit, $country),
            'groups' => $this->getFastestGroups->perPiecesCount($this->piecesCount, $this->limit, $country),
            default => [],
        };
    }
}
