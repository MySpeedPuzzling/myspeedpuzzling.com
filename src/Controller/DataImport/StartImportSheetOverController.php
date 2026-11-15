<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Exceptions\ImportMappingChangedMeanwhile;
use SpeedPuzzling\Web\Message\StartImportSheetOver;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Start over for this sheet" (docs/features/data-import/layouts.md): the sheet is proposed again without the
 * layout the player saved earlier. POST + CSRF, then back to the sheet. Changed meanwhile in another tab: the sheet
 * as it stands now, with a notice. Another player's import is a 404.
 */
final class StartImportSheetOverController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/sheets/{sheetId}/start-over',
        name: 'data_import_sheet_start_over',
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

        try {
            $this->messageBus->dispatch(new StartImportSheetOver($importId, $player->playerId, $sheetId, $request->request->getInt('version')));
            $this->addFlash('success', $this->translator->trans('data_import.layouts.flash.started_over'));
        } catch (HandlerFailedException $exception) {
            $cause = $exception->getPrevious();

            if ($cause instanceof DataImportNotFound) {
                throw $cause;
            }

            if ($cause instanceof ImportMappingChangedMeanwhile === false) {
                throw $exception;
            }

            $this->addFlash('warning', $this->translator->trans('data_import.columns.flash.changed_meanwhile'));
        }

        return $this->redirectToRoute('data_import_sheet', ['importId' => $importId, 'sheetId' => $sheetId]);
    }
}
