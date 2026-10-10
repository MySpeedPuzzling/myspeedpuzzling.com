<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetSolveXpDisplayInfo;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpCalculator;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\SolveXpContext;
use SpeedPuzzling\Web\Value\XpReason;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * Puzzle-detail XP estimate: one quiet line, "Solving this earns ~N XP, plus bonuses". Uses the real calculator so the
 * estimate never drifts from actual awards.
 *
 * N is the base (with the repeat discount when the viewer solved the puzzle before). The difficulty bonus is part of
 * it for members only: the difficulty tier is a member feature, and a number that includes it would reveal the tier to
 * everybody else. No bonus is promised as a number - which of them a solve gets (speed, weekly boost, warm-up,
 * unboxed) depends on that solve.
 */
#[AsTwigComponent]
final class XpPuzzleEstimate
{
    public null|string $puzzleId = null;

    public int $piecesCount = 0;

    public null|int $difficultyTier = null;

    public bool $visible = false;

    public int $baseXp = 0;

    /** Discount of a repeat solve in percent (50, 25), null for a first solve */
    public null|int $repeatPercent = null;

    public int $viewerSolveCount = 0;

    public function __construct(
        readonly private XpCalculator $xpCalculator,
        readonly private GetSolveXpDisplayInfo $getSolveXpDisplayInfo,
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

        // The opt-out rides on the viewer's own profile row - no query of its own
        if ($viewer !== null && $viewer->experienceSystemOptedOut) {
            return;
        }

        $isMember = $viewer !== null && $viewer->activeMembership;

        if ($viewer !== null) {
            $this->viewerSolveCount = $this->getSolveXpDisplayInfo->countPlayerSolvesOfPuzzle($viewer->playerId, $this->puzzleId);
        }

        $awards = $this->xpCalculator->calculate(new SolveXpContext(
            piecesCount: $this->piecesCount,
            difficultyTier: $isMember ? $this->difficultyTier : null,
            isTimed: true,
            isTeamOrDuo: false,
            unboxed: false,
            occurrenceIndex: $this->viewerSolveCount + 1,
            isBackfill: false,
        ));

        foreach ($awards as $award) {
            if ($award->reason === XpReason::SolveBase || $award->reason === XpReason::SolveDifficultyBonus) {
                $this->baseXp += $award->amount;
            }
        }

        $this->repeatPercent = match (true) {
            $this->viewerSolveCount === 0 => null,
            $this->viewerSolveCount === 1 => 50,
            default => 25,
        };

        $this->visible = true;
    }
}
