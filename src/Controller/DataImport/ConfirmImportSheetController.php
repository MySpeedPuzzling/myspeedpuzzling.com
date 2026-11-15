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
 * Saved: back to the sheet (or to the list, for the one-tap change there), whose rows are read again in the
 * background. Refused: the same page again with a 422 and the reason at its field - never a 200, never a lost
 * choice. Changed meanwhile in another tab: the sheet as it stands now, with a notice. Another player's import is a
 * 404.
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
        $back = $form->getString('back') === 'list'
            ? $this->redirectToRoute('data_import_columns', ['importId' => $importId])
            : $this->redirectToRoute('data_import_sheet', ['importId' => $importId, 'sheetId' => $sheetId]);

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

            return $back;
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

        return $back;
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
