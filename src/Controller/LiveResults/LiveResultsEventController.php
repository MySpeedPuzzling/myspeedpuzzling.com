<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\LiveResults;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetRoundResultsOverview;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\LiveResultsCurrentRound;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The live entry link of a whole event - the one to hand the referees: it always opens the current round
 * (LiveResultsCurrentRound), so it never goes stale during the day. `auto=1` lets the page prefer a round the
 * device picked by hand while that round still runs (parallel halls).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class LiveResultsEventController extends AbstractController
{
    public function __construct(
        private readonly GetRoundResultsOverview $getRoundResultsOverview,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(
        path: '/{_locale}/live-results/event/{competitionId}',
        name: 'live_results_event',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(string $competitionId): RedirectResponse
    {
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $current = LiveResultsCurrentRound::pick(
            $this->getRoundResultsOverview->forCompetition($competitionId),
            $this->clock->now(),
        );

        $response = $current === null
            ? $this->redirectToRoute('manage_competition_rounds', ['competitionId' => $competitionId])
            : $this->redirectToRoute('live_results', ['roundId' => $current->roundId, 'auto' => 1]);

        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
