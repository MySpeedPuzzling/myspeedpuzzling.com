<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Services\CompetitionParticipantExporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class DownloadCompetitionParticipantTemplateController extends AbstractController
{
    public function __construct(
        private readonly CompetitionParticipantExporter $exporter,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/sablona-ucastniku',
            'en' => '/en/participant-template',
        ],
        name: 'download_competition_participant_template',
    )]
    public function __invoke(): Response
    {
        $content = $this->exporter->downloadTemplate();

        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="participants-template.xlsx"');

        return $response;
    }
}
