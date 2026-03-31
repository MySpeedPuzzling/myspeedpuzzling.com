<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetPlayerRatingRanking;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\MspRatingCalculator;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsLiveComponent]
final class PlayerRatingProfile
{
    use DefaultActionTrait;

    #[LiveProp]
    public string $playerId = '';

    #[LiveProp]
    public bool $isOwnProfile = false;

    /** @var array<int, array{elo_rating: float, rank: int, total: int}> */
    public array $ratings = [];

    /** @var array{first_attempts: int, total_solves: int}|null */
    public null|array $ratingProgress = null;

    public int $minFirstAttempts = MspRatingCalculator::MINIMUM_FIRST_ATTEMPTS;

    public function __construct(
        readonly private GetPlayerRatingRanking $getPlayerRatingRanking,
        readonly private MspRatingCalculator $mspRatingCalculator,
    ) {
    }

    #[PostMount]
    #[PreReRender]
    public function populate(): void
    {
        $this->ratings = $this->getPlayerRatingRanking->allForPlayer($this->playerId);

        // If own profile and no rating data, show progress for 500pc
        if ($this->isOwnProfile && $this->ratings === []) {
            $progress = $this->mspRatingCalculator->getProgress($this->playerId, 500);

            if ($progress['first_attempts'] > 0 || $progress['total_solves'] > 0) {
                $this->ratingProgress = $progress;
            }
        }
    }
}
