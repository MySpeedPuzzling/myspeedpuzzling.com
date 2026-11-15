<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportAddScreen;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * **"What this import added"** (docs/features/data-import/guided-flow.md §7) - of one "Add" (`?batch=`) or of all -
 * fifty a page, the newest first, each with what it made and "Undo". By what a row made (`makes`), by its sheet
 * (`sheet`, an id) and by a part of the puzzle's name (`q` - the player's own text, the only thing of a file an
 * address holds); all of it paged in SQL. `select=1` turns the Undo buttons into tick boxes with "Undo the ticked":
 * a plain GET form to the step before an Undo (ImportUndoConfirmController), which names the rows by their ids.
 * Another player's import is a 404. Four statements, whatever the number of rows.
 */
final class ImportAddedRowsController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportAddScreen $getImportAddScreen,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/added',
        name: 'data_import_added',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $importId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $import = $this->getDataImports->byIdOfPlayer($importId, $player->playerId);
        $batchId = Uuid::isValid($request->query->getString('batch')) ? strtolower($request->query->getString('batch')) : null;
        $counts = $this->getImportAddScreen->counts($importId, $player->playerId, $batchId);

        $makes = in_array($request->query->getString('makes'), ['results', 'collection', 'wish'], true) ? $request->query->getString('makes') : null;
        $sheetId = Uuid::isValid($request->query->getString('sheet')) ? strtolower($request->query->getString('sheet')) : null;
        $search = trim(mb_substr($request->query->getString('q'), 0, 100));
        $selecting = $request->query->getString('select') === '1';

        $overview = $this->getImportAddScreen->addedOverview($importId, $player->playerId, $batchId, $sheetId, $search);
        $total = $overview['tabs'][$makes ?? 'all'];
        $pages = max(1, (int) ceil($total / GetImportAddScreen::ROWS_PER_PAGE));
        $page = min($pages, max(1, $request->query->getInt('page', 1)));

        return $this->render('data_import/added_rows.html.twig', [
            'import' => $import,
            'batch_id' => $batchId,
            'counts' => $counts,
            'rows' => $this->getImportAddScreen->added($importId, $player->playerId, $batchId, GetImportAddScreen::ROWS_PER_PAGE, ($page - 1) * GetImportAddScreen::ROWS_PER_PAGE, $makes, $sheetId, $search),
            'tabs' => $overview['tabs'],
            'sheets' => $overview['sheets'],
            'total' => $total,
            // What the address holds besides the page: ids, a kind, and the player's own search text
            'filters' => array_filter(['batch' => $batchId, 'makes' => $makes, 'sheet' => $sheetId, 'q' => $search !== '' ? $search : null, 'select' => $selecting ? '1' : null], static fn (null|string $value): bool => $value !== null),
            'selecting' => $selecting,
            'page' => $page,
            'pages' => $pages,
        ]);
    }
}
