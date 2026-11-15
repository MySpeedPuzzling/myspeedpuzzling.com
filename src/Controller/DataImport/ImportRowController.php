<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportRowsToFix;
use SpeedPuzzling\Web\Value\DataImport\ImportRowOrigin;
use SpeedPuzzling\Web\Services\DataImport\ImportRowSheet;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The row sheet of one import row (docs/features/data-import/pairing-screens.md "The row sheet"): what was
 * understood of it, and every field its sheet's mapping gives it as an input with the file's own text beside it.
 * `?return=` is the screen the player came from (ImportRowOrigin - only a screen of this import): the back link is
 * named after it and a save leads back there. Another player's import - or a row of another import - is a 404.
 * Three statements.
 */
final class ImportRowController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportRowsToFix $getImportRowsToFix,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/rows/{rowId}',
        name: 'data_import_row',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID, 'rowId' => Requirement::UUID],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $importId, string $rowId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $detail = $this->getImportRowsToFix->row($importId, $player->playerId, $rowId);

        if ($detail->beingSetUp === false) {
            return $this->redirectToRoute('data_import_detail', ['importId' => $importId]);
        }

        return $this->render('data_import/row.html.twig', [
            'import' => $this->getDataImports->byIdOfPlayer($importId, $player->playerId),
            'detail' => $detail,
            'fields' => ImportRowSheet::fields($detail),
            'form_error' => null,
            'origin' => ImportRowOrigin::tryFrom($request->query->getString('return'), $importId),
            // "Save and fix the next one" is offered while another row needs a look
            'has_next' => $this->getImportRowsToFix->nextRowId($importId, $player->playerId, $rowId) !== null,
        ]);
    }
}
