<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\UndoImportRows;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImport\AddImportRowsOutcome;
use SpeedPuzzling\Web\Value\DataImportUndoScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Undo (docs/features/data-import/adding-screens.md "Undo"): one row, one "Add", or everything the import added is
 * taken back (UndoImportRows) - the player's own word, from the form of the step before. POST + CSRF, `request_id`
 * as for "Add" (the same form sent again answers the same Undo); always a redirect: to the Undo's page, which
 * follows it, or back with "nothing to take back".
 *
 * `include_edited` - "remove it too" for a result the player changed since the import - is only ever sent for one
 * named row, by the button that says so (the engine takes it for chosen rows only). Undo is never paused. Another
 * player's import, batch or row is a 404 (the handler checks it).
 */
final class StartImportUndoController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/undo',
        name: 'data_import_undo_start',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID],
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $importId): Response
    {
        if ($this->isCsrfTokenValid(ImportsHomeController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $form = $request->request;
        $scope = DataImportUndoScope::tryFrom($form->getString('scope'));
        $rowId = strtolower($form->getString('row'));
        $batchId = strtolower($form->getString('batch'));
        $requestId = $form->getString('request_id');
        $back = $this->redirectToRoute('data_import_added', ['importId' => $importId]);

        if (
            $scope === null
            || Uuid::isValid($requestId) === false
            || ($scope === DataImportUndoScope::Rows && Uuid::isValid($rowId) === false)
            || ($scope === DataImportUndoScope::Batch && Uuid::isValid($batchId) === false)
        ) {
            $this->addFlash('info', $this->translator->trans('data_import.undo.flash.nothing'));

            return $back;
        }

        $outcome = $this->messageBus->dispatch(new UndoImportRows(
            $requestId,
            $importId,
            $player->playerId,
            $scope,
            batchId: $scope === DataImportUndoScope::Batch ? $batchId : null,
            rowIds: $scope === DataImportUndoScope::Rows ? [$rowId] : null,
            includeEdited: $scope === DataImportUndoScope::Rows && $form->getString('include_edited') === '1',
        ))->last(HandledStamp::class)?->getResult();
        assert($outcome instanceof AddImportRowsOutcome);

        if ($outcome->isStarted() === false || $outcome->id === null) {
            $this->addFlash('info', $this->translator->trans('data_import.undo.flash.nothing'));

            return $back;
        }

        return $this->redirectToRoute('data_import_undo_progress', array_filter([
            'importId' => $importId,
            'undoId' => $outcome->id,
            // Which row it was - an id - so the Undo's page can offer "choose its puzzle again"
            'row' => $scope === DataImportUndoScope::Rows ? $rowId : null,
        ]));
    }
}
