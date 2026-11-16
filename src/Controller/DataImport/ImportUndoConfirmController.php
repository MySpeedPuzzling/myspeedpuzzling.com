<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportAddScreen;
use SpeedPuzzling\Web\Query\GetUnlistedPuzzles;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImportUndoScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The step before an Undo (docs/features/data-import/adding-screens.md "Undo"): what would be taken back - one row
 * (`?row=`), the rows ticked on "What this import added" (`?rows[]=`), one "Add" (`?batch=`) or everything the
 * import added - in numbers, and what is kept: results the player
 * changed since stay. It only shows; the Undo is the POST of the form on it (StartImportUndoController). No
 * `confirm()` dialog anywhere. Another player's import, batch or row is a 404. Three statements at most.
 */
final class ImportUndoConfirmController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportAddScreen $getImportAddScreen,
        readonly private GetUnlistedPuzzles $getUnlistedPuzzles,
        readonly private TranslatorInterface $translator,
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

        // "Undo the ticked": the ids of the rows the list's boxes named - ids, never anything of a file
        $rowIds = array_slice(array_values(array_unique(array_map(strtolower(...), array_filter($request->query->all('rows'), static fn (mixed $id): bool => is_string($id) && Uuid::isValid($id))))), 0, StartImportUndoController::MAX_ROWS);
        $several = [];

        // The list's form sent with no box ticked must never read as "everything": back to the list, saying so
        if ($rowIds === [] && $request->query->has('ticked')) {
            $this->addFlash('info', $this->translator->trans('data_import.after.list.none_ticked'));

            return $this->redirectToRoute('data_import_added', array_filter(['importId' => $importId, 'batch' => Uuid::isValid($batchId) ? strtolower($batchId) : null, 'select' => '1']));
        }

        if (count($rowIds) === 1) {
            $rowId = $rowIds[0];
        }

        if (count($rowIds) > 1) {
            $scope = DataImportUndoScope::Rows;
            $counts = $this->getImportAddScreen->counts($importId, $player->playerId, rowIds: $rowIds);
            $several = $rowIds;
        } elseif ($rowId !== '') {
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

        // Of the rows kept just for the player, the ones matched since stay (the match's own Undo is first) - said
        // apart, so "removed" counts only what really goes. The player's own rows, read by their own reader
        $keptStaying = count($this->getUnlistedPuzzles->matchedImportRows($player->playerId, $counts['keptJustForYouRowIds']));

        return $this->render('data_import/undo_confirm.html.twig', [
            'kept_just_for_you_removed' => max(0, $counts['keptJustForYou'] - $keptStaying),
            'kept_just_for_you_staying' => $keptStaying,
            'import' => $import,
            'scope' => $scope->value,
            'row' => $row,
            // Several ticked rows: the Undo's form names them again
            'several' => $several,
            'batch' => $batch,
            'counts' => $counts,
            // One id per press: the same form sent again answers the same Undo
            'request_id' => Uuid::uuid7()->toString(),
        ]);
    }
}
