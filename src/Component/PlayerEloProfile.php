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

    /** @var array<int, array{elo_rating: int, rank: int, total: int}> */
    public array $eloRatings = [];

    /** @var array{first_attempts: int, total_solves: int}|null */
    public null|array $eloProgress = null;

    /** @var list<int> */
    public array $progressPieceCounts = [];

    public function __construct(
        readonly private GetPlayerEloRanking $getPlayerEloRanking,
        readonly private MspEloCalculator $mspEloCalculator,
    ) {
    }

    #[PostMount]
    #[PreReRender]
    public function populate(): void
    {
        $period = 'all-time';
        $this->eloRatings = $this->getPlayerEloRanking->allForPlayer($this->playerId, $period);

        // If own profile and no ELO data, show progress for common piece counts
        if ($this->isOwnProfile && $this->eloRatings === []) {
            $this->progressPieceCounts = [500, 1000];

            // Use the first piece count for progress display
            foreach ($this->progressPieceCounts as $pc) {
                $progress = $this->mspEloCalculator->getProgress($this->playerId, $pc);

                if ($progress['first_attempts'] > 0 || $progress['total_solves'] > 0) {
                    $this->eloProgress = $progress;
                    break;
                }
            }
        }
    }
}
