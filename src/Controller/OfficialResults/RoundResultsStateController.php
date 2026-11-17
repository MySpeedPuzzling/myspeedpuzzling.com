<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OfficialResults;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetRoundResultEntries;
use SpeedPuzzling\Web\Query\GetRoundResultsOverview;
use SpeedPuzzling\Web\Query\GetTimeProposals;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Security\CompetitionResultsEntryVoter;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Services\OfficialResultsLiveUpdates;
use SpeedPuzzling\Web\Services\OfficialResultsSubscription;
use SpeedPuzzling\Web\Services\RefereeEntriesView;
use SpeedPuzzling\Web\Value\RoundClock;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The organiser tools' bootstrap (live entry, results desk, seating): the round, every round of the event (round
 * picker, advancement targets, seating readiness) and every entry of the round with its official record, ranked.
 * The server's clock comes along for the stopwatch ("Finished now"), and a fresh live updates subscription (`mercure`,
 * OfficialResultsSubscription - only for whoever passed the voter) the pages renew their stream with. Referees may
 * read it (the live entry).
 *
 * Two opt-in query parameters (competition toolkit R §1.8) - without them the answer is the one every existing consumer
 * reads (pinned by RoundResultsStateGoldenTest):
 * - `?viewer=1` adds `viewer: {playerId, organiser}` - who reads it, so the live entry page renders without any user
 *   data of its own (it fills itself from this answer);
 * - `?entries=0` leaves `entries` out (and its query) - the clock sync and the token renewal only.
 * A `pick` round (competition toolkit A2) carries `puzzleFormat` in `round` and `rounds[]` (GetRoundResultsOverview,
 * RoundPuzzleFormats) - absent on every other round.
 * A competition with time proposals on (competition toolkit P1, R §1.8) adds `proposals`: the round's pending proposals
 * and those decided in the last 10 minutes (GetTimeProposals, TimeProposalView) - absent otherwise.
 * docs/features/competitions-management/official-results.md - the JSON shape is documented there.
 */
final class RoundResultsStateController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly GetRoundResultEntries $getRoundResultEntries,
        private readonly GetRoundResultsOverview $getRoundResultsOverview,
        private readonly OfficialResultsApi $api,
        private readonly ClockInterface $clock,
        private readonly OfficialResultsSubscription $subscription,
        private readonly RefereeEntriesView $refereeEntriesView,
        private readonly GetTimeProposals $getTimeProposals,
    ) {
    }

    #[Route(
        path: '/{_locale}/official-results/rounds/{roundId}',
        name: 'official_results_round_state',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $roundId): JsonResponse
    {
        $round = $this->roundRepository->get($roundId);
        $competition = $round->competition;
        $authorised = $this->api->authorise($request, $competition->id->toString(), write: false, attribute: CompetitionResultsEntryVoter::COMPETITION_RESULTS_ENTRY);

        if ($authorised instanceof JsonResponse) {
            return $authorised;
        }

        $withViewer = $request->query->getString('viewer') === '1';
        $withEntries = $request->query->getString('entries') !== '0';

        // A referee reads the round too (the live entry) - without private players' #codes (RefereeEntriesView)
        $organiser = $this->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $competition->id->toString());
        $rounds = $this->getRoundResultsOverview->forCompetition($competition->id->toString());
        $thisRound = null;
        foreach ($rounds as $overview) {
            if ($overview->roundId === $round->id->toString()) {
                $thisRound = $overview;
            }
        }

        // As late as possible - the clock offset of the live entry's "Finished now" (milliseconds: the ATOM form has
        // whole seconds only)
        $serverNow = $this->clock->now();

        $state = [
            'serverNow' => $serverNow->format(\DateTimeInterface::ATOM),
            'serverNowMs' => RoundClock::milliseconds($serverNow),
            'topic' => OfficialResultsLiveUpdates::topic($round->id->toString()),
            'competition' => [
                'id' => $competition->id->toString(),
                'name' => $competition->name,
                'isOnline' => $competition->isOnline,
            ],
            'round' => $thisRound,
            'rounds' => $rounds,
        ];

        if ($withEntries) {
            $state['entries'] = $this->refereeEntriesView->entries($this->getRoundResultEntries->forRound($round->id->toString()), $organiser);
        }

        // Only while the competition takes them - every other competition's state keeps its shape
        if ($competition->timeProposals) {
            $state['proposals'] = $this->getTimeProposals->forRound($round->id->toString(), $serverNow);
        }

        $state['mercure'] = $this->subscription->forRound($round->id->toString(), $organiser);

        if ($withViewer) {
            // The live entry: the outbox is this player's, the organiser tools show only for organisers
            $state['viewer'] = [
                'playerId' => $authorised,
                'organiser' => $organiser,
            ];
        }

        return OfficialResultsApi::json($state);
    }
}
