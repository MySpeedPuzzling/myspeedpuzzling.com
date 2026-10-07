<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OfficialResults;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetRoundResultEntries;
use SpeedPuzzling\Web\Query\GetRoundResultsOverview;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionResultsEntryVoter;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Services\OfficialResultsLiveUpdates;
use SpeedPuzzling\Web\Services\OfficialResultsSubscription;
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

        $rounds = $this->getRoundResultsOverview->forCompetition($competition->id->toString());
        $thisRound = null;
        foreach ($rounds as $overview) {
            if ($overview->roundId === $round->id->toString()) {
                $thisRound = $overview;
            }
        }

        return OfficialResultsApi::json([
            'serverNow' => $this->clock->now()->format(\DateTimeInterface::ATOM),
            'topic' => OfficialResultsLiveUpdates::topic($round->id->toString()),
            'competition' => [
                'id' => $competition->id->toString(),
                'name' => $competition->name,
                'isOnline' => $competition->isOnline,
            ],
            'round' => $thisRound,
            'rounds' => $rounds,
            'entries' => $this->getRoundResultEntries->forRound($round->id->toString()),
            'mercure' => $this->subscription->forRound($round->id->toString()),
        ]);
    }
}
