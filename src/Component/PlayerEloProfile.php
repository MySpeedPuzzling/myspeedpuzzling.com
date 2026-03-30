<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetPlayerEloRanking;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\MspEloCalculator;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsLiveComponent]
final class PlayerEloProfile
{
    use DefaultActionTrait;

    #[LiveProp]
    public string $playerId = '';

    #[LiveProp]
    public bool $isOwnProfile = false;

    /** @var array<int, array{elo_rating: float, rank: int, total: int}> */
    public array $eloRatings = [];

    /** @var array{first_attempts: int, total_solves: int}|null */
    public null|array $eloProgress = null;

    public function __construct(
        readonly private GetPlayerEloRanking $getPlayerEloRanking,
        readonly private MspEloCalculator $mspEloCalculator,
    ) {
    }

    #[PostMount]
    #[PreReRender]
    public function populate(): void
    {
        $this->eloRatings = $this->getPlayerEloRanking->allForPlayer($this->playerId);

        // If own profile and no ELO data, show progress for 500pc
        if ($this->isOwnProfile && $this->eloRatings === []) {
            $progress = $this->mspEloCalculator->getProgress($this->playerId, 500);

            if ($progress['first_attempts'] > 0 || $progress['total_solves'] > 0) {
                $this->eloProgress = $progress;
            }
        }
    }
}
