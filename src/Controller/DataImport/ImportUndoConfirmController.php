<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportAddScreen;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImportUndoScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The step before an Undo (docs/features/data-import/adding-screens.md "Undo"): what would be taken back - one row
 * (`?row=`), one "Add" (`?batch=`) or everything the import added - in numbers, and what is kept: results the player
 * changed since stay. It only shows; the Undo is the POST of the form on it (StartImportUndoController). No
 * `confirm()` dialog anywhere. Another player's import, batch or row is a 404. Three statements at most.
 */
final class ImportUndoConfirmController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportAddScreen $getImportAddScreen,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/undo',
        name: 'data_import_undo',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $importId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $import = $this->getDataImports->byIdOfPlayer($importId, $player->playerId);
        $rowId = $request->query->getString('row');
        $batchId = $request->query->getString('batch');
        $row = null;
        $batch = null;

        if ($rowId !== '') {
            $scope = DataImportUndoScope::Rows;
            $row = $this->getImportAddScreen->rowsByIds($importId, $player->playerId, [$rowId])[strtolower($rowId)] ?? throw new DataImportNotFound();
            $counts = $this->getImportAddScreen->counts($importId, $player->playerId, rowId: $row->rowId);
        } elseif ($batchId !== '') {
            $scope = DataImportUndoScope::Batch;
            $batch = $this->getImportAddScreen->batch($importId, $player->playerId, $batchId);
            $counts = $this->getImportAddScreen->counts($importId, $player->playerId, $batch['id']);
        } else {
            $scope = DataImportUndoScope::Import;
            $counts = $this->getImportAddScreen->counts($importId, $player->playerId);
        }

        return $this->render('data_import/undo_confirm.html.twig', [
            'import' => $import,
            'scope' => $scope->value,
            'row' => $row,
            'batch' => $batch,
            'counts' => $counts,
            // One id per press: the same form sent again answers the same Undo
            'request_id' => Uuid::uuid7()->toString(),
        ]);
    }
}
