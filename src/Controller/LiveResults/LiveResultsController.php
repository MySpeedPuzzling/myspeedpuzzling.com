<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\LiveResults;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetLiveResultsEntrant;
use SpeedPuzzling\Web\Query\GetLiveResultsEventPeople;
use SpeedPuzzling\Web\Query\GetRoundResultEntries;
use SpeedPuzzling\Web\Query\GetRoundResultsOverview;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Security\CompetitionResultsEntryVoter;
use SpeedPuzzling\Web\Services\OfficialResultsLiveUpdates;
use SpeedPuzzling\Web\Services\OfficialResultsSubscription;
use SpeedPuzzling\Web\Services\RefereeEntriesView;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The referee's phone during a round (docs/features/competitions-management/live-results.md): find an entrant by
 * table, name, #code or name-tag QR, enter the result, confirm, save - through an outbox that survives bad venue
 * Wi-Fi. The page carries the round's state so it renders at once; live_results_controller.js keeps it current.
 * `?entrant=<participantId>` opens that participant's entry (the name-tag QR route sends organisers here).
 * Organisers and the event's referees may open it; referees get no links to the other organiser tools.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class LiveResultsController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly GetRoundResultEntries $getRoundResultEntries,
        private readonly GetRoundResultsOverview $getRoundResultsOverview,
        private readonly GetLiveResultsEntrant $getLiveResultsEntrant,
        private readonly GetLiveResultsEventPeople $getLiveResultsEventPeople,
        private readonly ClockInterface $clock,
        private readonly OfficialResultsSubscription $subscription,
        private readonly RefereeEntriesView $refereeEntriesView,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/zadavani-vysledku/{roundId}',
            'en' => '/en/live-results/{roundId}',
            'es' => '/es/live-results/{roundId}',
            'ja' => '/ja/live-results/{roundId}',
            'fr' => '/fr/live-results/{roundId}',
            'de' => '/de/live-results/{roundId}',
        ],
        name: 'live_results',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $roundId): Response
    {
        $round = $this->roundRepository->get($roundId);
        $competition = $round->competition;
        $competitionId = $competition->id->toString();

        $this->denyAccessUnlessGranted(CompetitionResultsEntryVoter::COMPETITION_RESULTS_ENTRY, $competitionId);
        // A referee (live-results.md "Referees") gets the page without the organiser tools
        $organiser = $this->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $roundId = $round->id->toString();
        $rounds = $this->getRoundResultsOverview->forCompetition($competitionId);
        $thisRound = null;

        foreach ($rounds as $overview) {
            if ($overview->roundId === $roundId) {
                $thisRound = $overview;
            }
        }

        $entrantId = $request->query->getString('entrant');
        $entrant = $entrantId !== '' ? $this->getLiveResultsEntrant->ofCompetition($competitionId, $entrantId) : null;

        $response = $this->render('live_results/live_results.html.twig', [
            'competition' => $competition,
            'round' => $round,
            'round_overview' => $thisRound,
            'rounds' => $rounds,
            'entrant' => $entrant,
            'auto_picked' => $request->query->getBoolean('auto'),
            // The name tag scanned with the phone's camera is nobody of this event (any more)
            'unknown_tag' => $request->query->getString('notice') === 'tag_unknown',
            // Same shape as official_results_round_state - the page renders at once, the controller refreshes it
            'state' => [
                'serverNow' => $this->clock->now()->format(\DateTimeInterface::ATOM),
                'topic' => OfficialResultsLiveUpdates::topic($roundId),
                'competition' => [
                    'id' => $competitionId,
                    'name' => $competition->name,
                    'isOnline' => $competition->isOnline,
                ],
                'round' => $thisRound,
                'rounds' => $rounds,
                // A referee never sees a private player's #code (RefereeEntriesView) - nor on their live updates' topic
                'entries' => $this->refereeEntriesView->entries($this->getRoundResultEntries->forRound($roundId), $organiser),
                // The round's private topic + its stopwatch, for whoever passed the voter above (referees too)
                'mercure' => $this->subscription->forRound($roundId, $organiser),
            ],
            // Referees (live-results.md "Referees") get no links to the other organiser tools
            'organiser' => $organiser,
            // Quick add offers them before typing a name in again - put into the round by their id
            'event_people' => $this->getLiveResultsEventPeople->notInRound($competitionId, $roundId, withPrivateCodes: $organiser),
        ]);

        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
