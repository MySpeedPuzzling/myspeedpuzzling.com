<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Exceptions\ImportMappingChangedMeanwhile;
use SpeedPuzzling\Web\Exceptions\InvalidImportMapping;
use SpeedPuzzling\Web\Message\ConfirmImportMapping;
use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportSheetScreen;
use SpeedPuzzling\Web\Results\ImportSheetDetail;
use SpeedPuzzling\Web\Services\DataImport\ImportSheetPage;
use SpeedPuzzling\Web\Services\DataImport\ImportSheetScreen;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImport\ImportQuestionType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The player's word on one sheet (docs/features/data-import/columns-screen.md "Saving"): answers to its questions,
 * what it is, what a row is, what its columns are - one form, one message (ConfirmImportMapping). POST + CSRF.
 *
 * Saved: on to "Where you are" (the import's page - it says "Saved - your rows are being read again", follows the
 * reading live and names the next step), while the rows are read again in the background; a sheet saved as it was
 * moves on the same way. The one-tap change of the list leads back to the list (`back=list`), an answer given on
 * step 2's one-question screens to the next question (`back=check`). Refused: the same page again with a 422 and the
 * reason at its field - never a 200, never a lost choice. Changed meanwhile in another tab: the sheet as it stands
 * now, with a notice. Another player's import is a 404.
 */
final class ConfirmImportSheetController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportSheetScreen $getImportSheetScreen,
        readonly private ImportSheetScreen $screen,
        readonly private ImportSheetPage $page,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/sheets/{sheetId}',
        name: 'data_import_sheet_confirm',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID, 'sheetId' => Requirement::UUID],
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $importId, string $sheetId): Response
    {
        if ($this->isCsrfTokenValid(ImportsHomeController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $sheet = $this->getImportSheetScreen->sheet($importId, $sheetId, $player->playerId);
        $form = $request->request;
        $back = match ($form->getString('back')) {
            'list' => $this->redirectToRoute('data_import_columns', ['importId' => $importId]),
            // One question a screen (guided-flow.md §5.4): back to the row of questions, at the place it was asked at -
            // the answered one is gone, so the next one stands there now
            'check' => $this->redirectToRoute('data_import_columns', array_filter(['importId' => $importId, 'q' => max(0, $form->getInt('q'))])),
            default => null,
        };
        // The sheet's own Save moves on: "Where you are" follows the reading and names the next step (the flow
        // decides which - ImportFlow). Only a change made meanwhile leads back to the sheet itself
        $saved = $back ?? $this->redirectToRoute('data_import_detail', ['importId' => $importId]);
        $back ??= $this->redirectToRoute('data_import_sheet', ['importId' => $importId, 'sheetId' => $sheetId]);

        if ($sheet->beingSetUp === false) {
            return $this->redirectToRoute('data_import_detail', ['importId' => $importId]);
        }

        $submission = $this->screen->submission($sheet, $form);

        if ($submission['errors'] !== []) {
            return $this->refused($importId, $sheet, $player->playerId, $form, $submission['errors']);
        }

        // Nothing was changed: nothing is read again. A sheet whose set-up was given up is the exception - saving it
        // as it is, is how it is tried once more
        if ($submission['mapping'] === null && $submission['answers'] === [] && $submission['sheetWide'] === null && $sheet->failure === null) {
            $this->addFlash('info', $this->translator->trans('data_import.columns.flash.nothing_changed'));

            return $saved;
        }

        try {
            $this->messageBus->dispatch(new ConfirmImportMapping(
                importId: $importId,
                playerId: $player->playerId,
                sheetId: $sheetId,
                mappingVersion: $form->getInt('version'),
                mapping: $submission['mapping'],
                answers: $submission['answers'],
                sheetWide: $submission['sheetWide'],
            ));
        } catch (HandlerFailedException $exception) {
            $cause = $exception->getPrevious();

            if ($cause instanceof InvalidImportMapping) {
                return $this->refused($importId, $sheet, $player->playerId, $form, ['form' => 'refused']);
            }

            if ($cause instanceof ImportMappingChangedMeanwhile) {
                $this->addFlash('warning', $this->translator->trans('data_import.columns.flash.changed_meanwhile'));

                return $back;
            }

            if ($cause instanceof DataImportNotFound) {
                throw $cause;
            }

            throw $exception;
        }

        $this->addFlash('success', $this->translator->trans('data_import.columns.flash.saved'));

        // The sheet asked "did we read your columns right?" and the player said so (ImportCheckFirst): on to the next
        // sheet of the file that asks the same - else to "Where you are", which has the next step's button
        if (isset($submission['answers'][ImportQuestionType::CheckColumns->value])) {
            foreach ($this->getDataImports->sheetsOfPlayer($importId, $player->playerId) as $other) {
                if ($other->isHeldForACheck() && $other->sheetId !== strtolower($sheetId)) {
                    return $this->redirectToRoute('data_import_sheet', ['importId' => $importId, 'sheetId' => $other->sheetId]);
                }
            }

            return $this->redirectToRoute('data_import_detail', ['importId' => $importId]);
        }

        return $saved;
    }

    /**
     * @param InputBag<string> $form
     * @param array<string, string> $errors
     */
    private function refused(string $importId, ImportSheetDetail $sheet, string $playerId, InputBag $form, array $errors): Response
    {
        return $this->render(
            'data_import/sheet.html.twig',
            [
                ...$this->page->parameters($importId, $sheet, $playerId, $form, $errors),
                'import' => $this->getDataImports->byIdOfPlayer($importId, $playerId),
                'revision' => $this->getDataImports->progressOfPlayer($importId, $playerId)->revision,
            ],
            new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY),
        );
    }
}
