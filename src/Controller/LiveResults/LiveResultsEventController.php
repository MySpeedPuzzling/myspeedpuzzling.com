<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\LiveResults;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Query\GetRoundResultsOverview;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Security\CompetitionResultsEntryVoter;
use SpeedPuzzling\Web\Services\CompetitionDetailUrl;
use SpeedPuzzling\Web\Services\LiveResultsCurrentRound;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The live entry link of a whole event - the one to hand the referees (the referees page shows it with a QR): it
 * always opens the current round (LiveResultsCurrentRound), so it never goes stale during the day. `auto=1` lets the
 * page prefer a round the device picked by hand while that round still runs (parallel halls). Organisers and referees.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class LiveResultsEventController extends AbstractController
{
    public function __construct(
        private readonly GetRoundResultsOverview $getRoundResultsOverview,
        private readonly CompetitionDetailUrl $competitionDetailUrl,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/zadavani-vysledku-udalosti/{competitionId}',
            'en' => '/en/live-results/event/{competitionId}',
            'es' => '/es/live-results/event/{competitionId}',
            'ja' => '/ja/live-results/event/{competitionId}',
            'fr' => '/fr/live-results/event/{competitionId}',
            'de' => '/de/live-results/event/{competitionId}',
        ],
        name: 'live_results_event',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(string $competitionId): RedirectResponse
    {
        $this->denyAccessUnlessGranted(CompetitionResultsEntryVoter::COMPETITION_RESULTS_ENTRY, $competitionId);

        $current = LiveResultsCurrentRound::pick(
            $this->getRoundResultsOverview->forCompetition($competitionId),
            $this->clock->now(),
        );

        if ($current !== null) {
            $response = $this->redirectToRoute('live_results', ['roundId' => $current->roundId, 'auto' => 1]);
        } elseif ($this->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId)) {
            $response = $this->redirectToRoute('manage_competition_rounds', ['competitionId' => $competitionId]);
        } else {
            // A referee of an event without rounds yet - the event page (the round list is the organisers')
            try {
                $response = $this->redirect($this->competitionDetailUrl->of($competitionId));
            } catch (CompetitionNotFound) {
                $response = $this->redirectToRoute('events');
            }
        }

        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
