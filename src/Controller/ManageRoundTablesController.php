<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ManageRoundTablesController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly GetCompetitionEvents $getCompetitionEvents,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/sprava-stolu-kola/{roundId}',
            'en' => '/en/manage-round-tables/{roundId}',
            'es' => '/es/manage-round-tables/{roundId}',
            'ja' => '/ja/manage-round-tables/{roundId}',
            'fr' => '/fr/manage-round-tables/{roundId}',
            'de' => '/de/manage-round-tables/{roundId}',
        ],
        name: 'manage_round_tables',
    )]
    public function __invoke(string $roundId): Response
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $competitionId = $round->competition->id->toString();

        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $competition = $this->getCompetitionEvents->byId($competitionId);

        return $this->render('manage_round_tables.html.twig', [
            'competition' => $competition,
            'round' => $round,
        ]);
    }
}
