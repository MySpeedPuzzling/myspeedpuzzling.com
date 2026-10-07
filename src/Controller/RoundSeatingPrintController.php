<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetRoundResultEntries;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Results\RoundResultEntry;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\SeatingPrintList;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The printable seating list of a round (docs/features/competitions-management/seating.md) - a standalone page like
 * the table layout print: by table number, and "find your table" - every person's name alphabetically pointing to
 * their table (each member of a pair/team to the pair's/team's table), to post at the venue's entrance.
 * `?list=tables` or `?list=names` prints one of them.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class RoundSeatingPrintController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly GetRoundResultEntries $getRoundResultEntries,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/tisk-zasedaciho-poradku-kola/{roundId}',
            'en' => '/en/print-round-seating/{roundId}',
            'es' => '/es/print-round-seating/{roundId}',
            'ja' => '/ja/print-round-seating/{roundId}',
            'fr' => '/fr/print-round-seating/{roundId}',
            'de' => '/de/print-round-seating/{roundId}',
        ],
        name: 'round_seating_print',
        methods: ['GET'],
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
    )]
    public function __invoke(Request $request, string $roundId): Response
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $competition = $round->competition;
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competition->id->toString());

        $list = $request->query->getString('list');
        if (!in_array($list, ['tables', 'names'], true)) {
            $list = 'both';
        }

        $entries = $this->getRoundResultEntries->forRound($round->id->toString());

        $response = $this->render('seating/print.html.twig', [
            'competition' => $competition,
            'round' => $round,
            'list' => $list,
            'by_table' => SeatingPrintList::byTable($entries),
            'by_name' => SeatingPrintList::byName($entries, $request->getLocale()),
            'without_table' => count(array_filter($entries, static fn (RoundResultEntry $entry): bool => $entry->tableNumber === null)),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
