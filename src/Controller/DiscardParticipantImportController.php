<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantImportStash;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Start over" on the import preview: the uploaded file (personal data) is removed at once, not after 24 hours
 * (docs/features/competitions-management/participant-import-preview.md D9).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class DiscardParticipantImportController extends AbstractController
{
    public function __construct(
        private readonly ParticipantImportStash $stash,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/import-ucastniku-udalosti/{competitionId}/{token}/zahodit',
            'en' => '/en/import-event-participants/{competitionId}/{token}/discard',
            'es' => '/es/import-event-participants/{competitionId}/{token}/discard',
            'ja' => '/ja/import-event-participants/{competitionId}/{token}/discard',
            'fr' => '/fr/import-event-participants/{competitionId}/{token}/discard',
            'de' => '/de/import-event-participants/{competitionId}/{token}/discard',
        ],
        name: 'participant_import_discard',
        requirements: ['token' => ParticipantImportPreviewController::TOKEN_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $competitionId, string $token): Response
    {
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        if ($this->isCsrfTokenValid(ParticipantImportPreviewController::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            $this->stash->discard($token, $competitionId);
            $this->addFlash('info', $this->translator->trans('competition.participants.import.confirm.discarded'));
        } else {
            $this->addFlash('danger', $this->translator->trans('competition.participants.import.confirm.expired'));
        }

        return $this->redirectToRoute('manage_competition_participants', [
            'competitionId' => $competitionId,
        ], Response::HTTP_SEE_OTHER);
    }
}
