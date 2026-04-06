<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\CompetitionParticipantExporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ExportCompetitionParticipantsController extends AbstractController
{
    public function __construct(
        private readonly CompetitionParticipantExporter $exporter,
        private readonly GetCompetitionEvents $getCompetitionEvents,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/export-ucastniku-udalosti/{competitionId}',
            'en' => '/en/export-event-participants/{competitionId}',
        ],
        name: 'export_competition_participants',
    )]
    public function __invoke(string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $competition = $this->getCompetitionEvents->byId($competitionId);
        $content = $this->exporter->export($competitionId);

        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="participants-%s.xlsx"', $competition->slug));

        return $response;
    }
}
