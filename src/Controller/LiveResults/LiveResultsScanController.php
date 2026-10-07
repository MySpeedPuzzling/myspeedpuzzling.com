<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\LiveResults;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Query\GetLiveResultsEntrant;
use SpeedPuzzling\Web\Query\GetRoundResultsOverview;
use SpeedPuzzling\Web\Results\RoundResultsOverview;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\CompetitionDetailUrl;
use SpeedPuzzling\Web\Services\LiveResultsCurrentRound;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The URL in a name tag's QR (docs/features/competitions-management/live-results.md). An organiser of the event
 * (their phone's camera) lands on the live entry of the participant's current round with their entry open - their
 * own round when they are in several, so parallel halls each get theirs. Anyone else - signed out, a puzzler, an
 * unknown or another event's participant - lands on the event page.
 */
final class LiveResultsScanController extends AbstractController
{
    public function __construct(
        private readonly GetLiveResultsEntrant $getLiveResultsEntrant,
        private readonly GetRoundResultsOverview $getRoundResultsOverview,
        private readonly CompetitionDetailUrl $competitionDetailUrl,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(
        path: '/{_locale}/live/{competitionId}/p/{participantId}',
        name: 'live_results_scan',
        requirements: [
            '_locale' => 'en|cs|es|ja|fr|de',
            'competitionId' => FirstTryConflictsController::ID_REQUIREMENT,
            'participantId' => FirstTryConflictsController::ID_REQUIREMENT,
        ],
        methods: ['GET'],
    )]
    public function __invoke(string $competitionId, string $participantId): RedirectResponse
    {
        $entrant = null;

        if ($this->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId)) {
            $entrant = $this->getLiveResultsEntrant->ofCompetition($competitionId, $participantId);
        }

        $round = null;

        if ($entrant !== null) {
            $rounds = $this->getRoundResultsOverview->forCompetition($competitionId);
            $theirs = array_values(array_filter(
                $rounds,
                static fn (RoundResultsOverview $round): bool => in_array($round->roundId, $entrant->roundIds, true),
            ));
            $now = $this->clock->now();
            $round = LiveResultsCurrentRound::pick($theirs, $now) ?? LiveResultsCurrentRound::pick($rounds, $now);
        }

        if ($entrant !== null && $round !== null) {
            $response = $this->redirectToRoute('live_results', [
                'roundId' => $round->roundId,
                'entrant' => $entrant->participantId,
            ]);
        } else {
            try {
                $response = $this->redirect($this->competitionDetailUrl->of($competitionId));
            } catch (CompetitionNotFound) {
                $response = $this->redirectToRoute('events');
            }
        }

        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
