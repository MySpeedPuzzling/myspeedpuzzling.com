<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\DraftNotVisible;
use SpeedPuzzling\Web\Exceptions\OnlineRoundNotFound;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Results\OnlineWatchRound;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\LiveKit\LiveKitSettings;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Who may watch a round's studio - the one rule of the watch page and its token endpoint (docs/features/
 * online-competitions/contracts/phase-1b.md §5 `online_watch`, `online_watch_token`):
 * - a round of an event that is publicly visible (Competition::isPubliclyVisible() - approved, not rejected, never a
 *   draft, an edition by its series): anybody, signed in or not;
 * - a draft, an event waiting for approval or a rejected one: only whoever may edit it (COMPETITION_EDIT) - a 404 for
 *   everybody else (DraftNotVisible, docs/features/organizations/README.md "Drafts"). The voter is asked only then, so
 *   a public studio costs no statement for it and never touches a visitor's session;
 * - a round without online timing has no watch page (OnlineRoundNotFound).
 * Whether there is video to watch is the caller's to show: a media server configured and the round's video on.
 */
final readonly class OnlineWatchGate
{
    public function __construct(
        private CompetitionRoundRepository $competitionRoundRepository,
        private OnlineRoundRepository $onlineRoundRepository,
        private AuthorizationCheckerInterface $authorizationChecker,
        private LiveKitSettings $liveKitSettings,
    ) {
    }

    /**
     * @throws CompetitionRoundNotFound
     * @throws DraftNotVisible
     * @throws OnlineRoundNotFound
     */
    public function open(string $roundId): OnlineWatchRound
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $competition = $round->competition;
        $isPublic = $competition->isPubliclyVisible();

        if ($isPublic === false && $this->authorizationChecker->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $competition->id->toString()) === false) {
            throw new DraftNotVisible();
        }

        $onlineRound = $this->onlineRoundRepository->ofRound($round->id->toString()) ?? throw new OnlineRoundNotFound();

        return new OnlineWatchRound(
            round: $round,
            onlineRound: $onlineRound,
            isPublic: $isPublic,
            videoAvailable: $this->liveKitSettings->isAvailable() && $onlineRound->videoEnabled,
        );
    }
}
