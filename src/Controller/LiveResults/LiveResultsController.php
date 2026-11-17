<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\LiveResults;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetLiveResultsEntrant;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionResultsEntryVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The referee's phone during a round (docs/features/competitions-management/live-results.md): find an entrant by
 * table, name, #code or name-tag QR, enter the result, confirm, save - through an outbox that survives bad venue
 * Wi-Fi. The page carries no data of the round or of the person reading it (competition toolkit C1 - the same markup can
 * be kept as an offline shell): live_results_controller.js fills it from official_results_round_state?viewer=1, one
 * fetch, and keeps it current. Landing parameters, handed to the page as they are (checked here, resolved in JS):
 * `?entrant=<participantId>` (an active participant of the event - the name-tag QR route sends organisers here),
 * `?table=<n>` (opens the entry at that table), `?entry=<participant_round:id|team:id>` (opens that entry).
 * Organisers and the event's referees may open it; referees get no links to the other organiser tools (shown by the
 * page only for `viewer.organiser`).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class LiveResultsController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly GetLiveResultsEntrant $getLiveResultsEntrant,
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

        $entrantId = $request->query->getString('entrant');
        $entrant = $entrantId !== '' ? $this->getLiveResultsEntrant->ofCompetition($competitionId, $entrantId) : null;
        $table = $request->query->getString('table');
        $entry = strtolower($request->query->getString('entry'));

        $response = $this->render('live_results/live_results.html.twig', [
            'round_id' => $round->id->toString(),
            // Only ids and numbers - whose entry it is, the page finds in the round's state
            'entrant_id' => $entrant?->participantId,
            'table' => preg_match('/^[1-9]\d{0,3}$/', $table) === 1 ? (int) $table : null,
            'entry' => preg_match('/^(participant_round|team):' . FirstTryConflictsController::ID_REQUIREMENT . '$/', $entry) === 1 ? $entry : null,
            'auto_picked' => $request->query->getBoolean('auto'),
            // The name tag scanned with the phone's camera is nobody of this event (any more)
            'unknown_tag' => $request->query->getString('notice') === 'tag_unknown',
        ]);

        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
