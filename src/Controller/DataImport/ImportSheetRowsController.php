<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportSheetScreen;
use SpeedPuzzling\Web\Services\DataImport\ImportSheetPage;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The rows of one sheet that need a look (docs/features/data-import/columns-screen.md): fifty a page, each with what
 * was understood of it and - in plain words, the value shown - what was not. Read only: fixing single rows is a
 * later stage's. Another player's import is a 404. Three statements whatever the sheet's size.
 */
final class ImportSheetRowsController extends AbstractController
{
    public const int ROWS_PER_PAGE = 50;

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportSheetScreen $getImportSheetScreen,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/sheets/{sheetId}/need-a-look',
        name: 'data_import_sheet_rows',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID, 'sheetId' => Requirement::UUID],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $importId, string $sheetId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $sheet = $this->getImportSheetScreen->sheet($importId, $sheetId, $player->playerId);

        if ($sheet->beingSetUp === false) {
            return $this->redirectToRoute('data_import_detail', ['importId' => $importId]);
        }

        $state = ImportSheetPage::state($sheet);
        $total = $state === 'parsed' ? $sheet->rowsNeedLook : 0;
        $pages = max(1, (int) ceil($total / self::ROWS_PER_PAGE));
        $page = min($pages, max(1, $request->query->getInt('page', 1)));

        return $this->render('data_import/sheet_rows.html.twig', [
            'import' => $this->getDataImports->byIdOfPlayer($importId, $player->playerId),
            'import_id' => $importId,
            'sheet' => $sheet,
            'state' => $state,
            'rows' => $total === 0
                ? []
                : $this->getImportSheetScreen->understoodRows($importId, $sheetId, $player->playerId, true, self::ROWS_PER_PAGE, ($page - 1) * self::ROWS_PER_PAGE),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ]);
    }
}
