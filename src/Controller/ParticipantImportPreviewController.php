<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * PLACEHOLDER - replaced by the UI package (the preview of docs/features/competitions-management/participant-import-preview.md).
 * Only here so the upload has a route to redirect to.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ParticipantImportPreviewController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/import-ucastniku-udalosti/{competitionId}/{token}',
            'en' => '/en/import-event-participants/{competitionId}/{token}',
            'es' => '/es/import-event-participants/{competitionId}/{token}',
            'ja' => '/ja/import-event-participants/{competitionId}/{token}',
            'fr' => '/fr/import-event-participants/{competitionId}/{token}',
            'de' => '/de/import-event-participants/{competitionId}/{token}',
        ],
        name: 'participant_import_preview',
        methods: ['GET'],
    )]
    public function __invoke(string $competitionId, string $token): Response
    {
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        return new Response('Participant import preview', headers: [
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
