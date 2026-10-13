<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\DraftNotVisible;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Results\RoundStopwatchState;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The round's stopwatch for a big screen (docs/features/competitions-management/README.md "Round Stopwatch"): public,
 * the clock every device of the round shows - a countdown before a scheduled start ("Starts in 0:00:09", the last ten
 * seconds big), then the time since the start until the round's time limit.
 */
final class RoundStopwatchController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/stopky-kola/{roundId}',
            'en' => '/en/round-stopwatch/{roundId}',
            'es' => '/es/round-stopwatch/{roundId}',
            'ja' => '/ja/round-stopwatch/{roundId}',
            'fr' => '/fr/round-stopwatch/{roundId}',
            'de' => '/de/round-stopwatch/{roundId}',
        ],
        name: 'round_stopwatch',
    )]
    public function __invoke(string $roundId): Response
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $canManage = $this->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $round->competition->id->toString());

        // The shared stopwatch names the event and the round - of a draft only for its team and admins
        // (docs/features/organizations/README.md "Drafts")
        if ($canManage === false && $round->competition->isHiddenAsDraft()) {
            throw new DraftNotVisible();
        }

        $now = $this->clock->now();

        return $this->render('round_stopwatch.html.twig', [
            'round' => $round,
            'competition' => $round->competition,
            'canManage' => $canManage,
            'stopwatch' => RoundStopwatchState::ofRound($round, $now),
            'serverNowMs' => RoundStopwatchState::milliseconds($now),
            'stateUrl' => $this->generateUrl('round_stopwatch_state', ['roundId' => $round->id->toString()]),
        ]);
    }
}
