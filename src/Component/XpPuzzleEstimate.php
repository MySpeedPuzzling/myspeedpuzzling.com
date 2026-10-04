<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetSolveXpDisplayInfo;
use SpeedPuzzling\Web\Query\GetXpProfile;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpCalculator;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\SolveXpContext;
use SpeedPuzzling\Web\Value\SpeedPercentile;
use SpeedPuzzling\Web\Value\XpReason;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * Puzzle-detail XP estimate (§1.9): "Solving this earns ~N XP + up to M bonus",
 * a personalized repeat note when the viewer already solved the puzzle, and the
 * pending note for unrated puzzles. Uses the real calculator so the estimate can
 * never drift from actual awards.
 */
#[AsTwigComponent]
final class XpPuzzleEstimate
{
    public null|string $puzzleId = null;

    public int $piecesCount = 0;

    public null|int $difficultyTier = null;

    public bool $visible = false;

    public int $baseXp = 0;

    public int $bonusXp = 0;

    public int $viewerSolveCount = 0;

    public function __construct(
        readonly private XpCalculator $xpCalculator,
        readonly private GetSolveXpDisplayInfo $getSolveXpDisplayInfo,
        readonly private GetXpProfile $getXpProfile,
        readonly private XpFeatureGate $xpFeatureGate,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[PostMount]
    public function load(): void
    {
        if ($this->puzzleId === null || $this->piecesCount <= 0) {
            return;
        }

        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        if ($this->xpFeatureGate->isVisibleFor($viewer) === false) {
            return;
        }

        if ($viewer !== null && $this->getXpProfile->byPlayerId($viewer->playerId)->optedOut) {
            return;
        }

        $awards = $this->xpCalculator->calculate(new SolveXpContext(
            piecesCount: $this->piecesCount,
            difficultyTier: $this->difficultyTier,
            isTimed: true,
            isTeamOrDuo: false,
            unboxed: false,
            occurrenceIndex: 1,
            isBackfill: false,
            speedPercentile: SpeedPercentile::Top10,
            xpEarningSolvesThisWeek: 0,
            isFirstXpEarningSolveOfDay: true,
        ));

        foreach ($awards as $award) {
            if ($award->reason === XpReason::SolveBase || $award->reason === XpReason::SolveDifficultyBonus) {
                $this->baseXp += $award->amount;
            } else {
                $this->bonusXp += $award->amount;
            }
        }

        if ($viewer !== null) {
            $this->viewerSolveCount = $this->getSolveXpDisplayInfo->countPlayerSolvesOfPuzzle($viewer->playerId, $this->puzzleId);
        }

        $this->visible = true;
    }

    public function isUnrated(): bool
    {
        return $this->difficultyTier === null;
    }
}
