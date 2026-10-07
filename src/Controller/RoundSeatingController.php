<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetRoundResultEntries;
use SpeedPuzzling\Web\Query\GetRoundResultsOverview;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\MercureTopicCollector;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Services\OfficialResultsLiveUpdates;
use SpeedPuzzling\Web\Services\SeatingProposer;
use SpeedPuzzling\Web\Value\SeatingSource;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Seating of a round - table numbers before an in-person round (docs/features/competitions-management/seating.md):
 * the event's organisers number the entrants by hand, by drag and drop, or apply an auto-assign proposal; every write
 * goes through the official results endpoints and other organisers' pages follow over Mercure. Online events have no
 * seating - the page says so.
 *
 * `?propose=` opens the auto-assign proposal straight away ("Seat them now" after advancing): `auto` = the best
 * available source, or a SeatingSource value; `?first=` pre-fills its first table number.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class RoundSeatingController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly GetRoundResultEntries $getRoundResultEntries,
        private readonly GetRoundResultsOverview $getRoundResultsOverview,
        private readonly MercureTopicCollector $mercureTopicCollector,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/zasedaci-poradek-kola/{roundId}',
            'en' => '/en/round-seating/{roundId}',
            'es' => '/es/round-seating/{roundId}',
            'ja' => '/ja/round-seating/{roundId}',
            'fr' => '/fr/round-seating/{roundId}',
            'de' => '/de/round-seating/{roundId}',
        ],
        name: 'round_seating',
        methods: ['GET'],
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
    )]
    public function __invoke(Request $request, string $roundId): Response
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $competition = $round->competition;
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competition->id->toString());

        $overview = null;
        $entries = [];
        // The round switch: the event's rounds (online events have no seating to switch to)
        $rounds = [];

        if ($competition->isOnline === false) {
            $rounds = $this->getRoundResultsOverview->forCompetition($competition->id->toString());
            foreach ($rounds as $candidate) {
                if ($candidate->roundId === $round->id->toString()) {
                    $overview = $candidate;
                }
            }

            $overview ??= $this->getRoundResultsOverview->forRound($round->id->toString());
            $entries = $this->getRoundResultEntries->forRound($round->id->toString());
            $this->mercureTopicCollector->addTopic(OfficialResultsLiveUpdates::topic($round->id->toString()));
        }

        $propose = $request->query->getString('propose');
        if ($propose !== 'auto' && SeatingSource::tryFrom($propose) === null) {
            $propose = '';
        }

        // The proposal's first table (a second hall numbered from 101)
        $first = $request->query->getString('first');
        $firstTable = preg_match('/^\d{1,4}$/', $first) === 1 ? (int) $first : 1;
        if ($firstTable < 1 || $firstTable > SeatingProposer::MAX_TABLE_NUMBER) {
            $firstTable = 1;
        }

        $response = $this->render('seating/round_seating.html.twig', [
            'competition' => $competition,
            'round' => $round,
            'overview' => $overview,
            'rounds' => $rounds,
            'entries' => $entries,
            'propose' => $propose,
            'first_table' => $firstTable,
            'csrf_token_id' => OfficialResultsApi::CSRF_TOKEN_ID,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
