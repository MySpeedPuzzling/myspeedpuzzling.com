<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportAddScreen;
use SpeedPuzzling\Web\Results\ImportAddRow;
use SpeedPuzzling\Web\Services\DataImport\ImportRowAnswers;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImport\ImportRowRefusal;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The rows an "Add" took and could not add (docs/features/data-import/adding-screens.md "The lists") - of one "Add"
 * (`?batch=`) or of all - fifty a page, grouped by the reason, each with the reason in a plain sentence and, where
 * it helps, the way to settle it: the row's sheet, the puzzle's chooser, "It is right" / "Add it anyway", "Try
 * again" - with the result a "similar result" question is about beside its row, and one answer for all rows that
 * say pair or team and name nobody. Another player's import is a 404. Five statements at most, whatever the number
 * of rows.
 */
final class ImportNotAddedRowsController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportAddScreen $getImportAddScreen,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/not-added',
        name: 'data_import_not_added',
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
        $pages = max(1, (int) ceil($counts['notAdded'] / GetImportAddScreen::ROWS_PER_PAGE));
        $page = min($pages, max(1, $request->query->getInt('page', 1)));
        $rows = $this->getImportAddScreen->notAdded($importId, $player->playerId, $batchId, GetImportAddScreen::ROWS_PER_PAGE, ($page - 1) * GetImportAddScreen::ROWS_PER_PAGE);
        $requestIds = [];

        foreach ($rows as $row) {
            // One id per row's button: the same form sent again (a double tap) answers the same "Add"
            $requestIds[$row->rowId] = Uuid::uuid7()->toString();
        }

        return $this->render('data_import/not_added_rows.html.twig', [
            'import' => $import,
            'batch_id' => $batchId,
            'counts' => $counts,
            'rows' => $rows,
            'request_ids' => $requestIds,
            // The results a "similar result" / "somebody else's result" question is about, beside their rows
            'beside' => $this->getImportAddScreen->resultsBeside($importId, $player->playerId, array_values(array_map(static fn (ImportAddRow $row): string => $row->rowId, array_filter($rows, static fn (ImportAddRow $row): bool => in_array($row->failure, ['similar_result_exists', 'somebody_elses_result'], true))))),
            // One answer for all rows that say pair or team and name nobody
            'people_rows' => count($this->getImportAddScreen->rowIdsNotAddedFor($importId, $player->playerId, ImportRowRefusal::PeopleDoNotFitType->value, $batchId, ImportRowAnswers::MAX_ROWS)),
            'people_request_id' => Uuid::uuid7()->toString(),
            'page' => $page,
            'pages' => $pages,
            'wavable' => GetImportAddScreen::WAVABLE,
        ]);
    }
}
