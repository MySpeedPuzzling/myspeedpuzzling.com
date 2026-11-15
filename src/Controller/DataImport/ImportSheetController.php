<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportSheetScreen;
use SpeedPuzzling\Web\Services\DataImport\ImportSheetPage;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One sheet of the signed-in player's import (docs/features/data-import/columns-screen.md): its questions, its
 * columns, what a row is, and the first rows as they were understood. Another player's import - or a sheet of
 * another import - is a 404.
 */
final class ImportSheetController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportSheetScreen $getImportSheetScreen,
        readonly private ImportSheetPage $page,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/sheets/{sheetId}',
        name: 'data_import_sheet',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID, 'sheetId' => Requirement::UUID],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(string $importId, string $sheetId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $sheet = $this->getImportSheetScreen->sheet($importId, $sheetId, $player->playerId);

        if ($sheet->beingSetUp === false) {
            return $this->redirectToRoute('data_import_detail', ['importId' => $importId]);
        }

        return $this->render('data_import/sheet.html.twig', [
            ...$this->page->parameters($importId, $sheet, $player->playerId),
            'import' => $this->getDataImports->byIdOfPlayer($importId, $player->playerId),
        ]);
    }
}
