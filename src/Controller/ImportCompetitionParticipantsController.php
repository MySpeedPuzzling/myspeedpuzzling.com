<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\ParticipantFileUnreadable;
use SpeedPuzzling\Web\FormData\ExcelImportFormData;
use SpeedPuzzling\Web\FormType\ExcelImportFormType;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantImportStash;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ParticipantFileFormat;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Upload of a participant list: nothing is imported here. The file is kept (ParticipantImportStash) and the organiser
 * goes on to the preview, where the columns are chosen and the import confirmed
 * (docs/features/competitions-management/participant-import-preview.md, D2 + D9).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ImportCompetitionParticipantsController extends AbstractController
{
    private const string MESSAGE_PREFIX = 'competition.participants.import.';

    public function __construct(
        private readonly ParticipantImportStash $stash,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/import-ucastniku-udalosti/{competitionId}',
            'en' => '/en/import-event-participants/{competitionId}',
            'es' => '/es/import-event-participants/{competitionId}',
            'ja' => '/ja/import-event-participants/{competitionId}',
            'fr' => '/fr/import-event-participants/{competitionId}',
            'de' => '/de/import-event-participants/{competitionId}',
        ],
        name: 'import_competition_participants',
        methods: ['POST'],
    )]
    public function __invoke(string $competitionId, Request $request): Response
    {
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $formData = new ExcelImportFormData();
        $form = $this->createForm(ExcelImportFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() === false || $form->isValid() === false || $formData->file === null) {
            return $this->backWithError($competitionId, $this->formErrorMessage($form));
        }

        $file = $formData->file;

        if (ParticipantFileFormat::fromFileName($file->getClientOriginalName()) === null) {
            return $this->backWithError($competitionId, $this->translator->trans('competition.participants.import.upload.invalid_type'));
        }

        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            throw $this->createAccessDeniedException();
        }

        $stashed = $this->stash->keep($file, $competitionId, $player->playerId);

        if ($stashed === null) {
            return $this->backWithError($competitionId, $this->translator->trans('competition.participants.import.upload.failed'));
        }

        try {
            // Read once right away: a file that is no participant list is refused here, not on the preview
            $this->stash->sheets($stashed->token, $competitionId);
        } catch (ParticipantFileUnreadable $e) {
            $this->stash->discard($stashed->token, $competitionId);

            return $this->backWithError($competitionId, $e->trans($this->translator));
        }

        return $this->redirectToRoute('participant_import_preview', [
            'competitionId' => $competitionId,
            'token' => $stashed->token,
        ], Response::HTTP_SEE_OTHER);
    }

    private function backWithError(string $competitionId, string $message): Response
    {
        $this->addFlash('danger', $message);

        return $this->redirectToRoute('participants_sheet', [
            'competitionId' => $competitionId,
        ], Response::HTTP_SEE_OTHER);
    }

    /**
     * The constraint messages of ExcelImportFormData are keys of the `messages` domain; anything else (CSRF, a partial
     * upload, …) is told as a failed upload.
     *
     * @param FormInterface<ExcelImportFormData> $form
     */
    private function formErrorMessage(FormInterface $form): string
    {
        if ($form->isSubmitted() === false) {
            return $this->translator->trans('forms.invalid_file_upload');
        }

        foreach ($form->getErrors(true) as $error) {
            if (str_starts_with($error->getMessageTemplate(), self::MESSAGE_PREFIX)) {
                return $this->translator->trans($error->getMessageTemplate(), [
                    '%max%' => rtrim(ExcelImportFormData::MAX_SIZE, 'M') . ' MB',
                ]);
            }
        }

        return $this->translator->trans('competition.participants.import.upload.failed');
    }
}
