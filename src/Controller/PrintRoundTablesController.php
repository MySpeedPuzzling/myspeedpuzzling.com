<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetTableLayoutForRound;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class PrintRoundTablesController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly GetTableLayoutForRound $getTableLayoutForRound,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/tisk-stolu-kola/{roundId}',
            'en' => '/en/print-round-tables/{roundId}',
            'es' => '/es/print-round-tables/{roundId}',
            'ja' => '/ja/print-round-tables/{roundId}',
            'fr' => '/fr/print-round-tables/{roundId}',
            'de' => '/de/print-round-tables/{roundId}',
        ],
        name: 'print_round_tables',
    )]
    public function __invoke(string $roundId): Response
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $competitionId = $round->competition->id->toString();

        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $competition = $this->getCompetitionEvents->byId($competitionId);
        $rows = $this->getTableLayoutForRound->byRoundId($roundId);

        return $this->render('print_round_tables.html.twig', [
            'competition' => $competition,
            'round' => $round,
            'rows' => $rows,
        ]);
    }
}
