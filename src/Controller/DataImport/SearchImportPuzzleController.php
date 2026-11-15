<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportPuzzleScreen;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImportStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Search the database" of the chooser (docs/features/data-import/pairing-screens.md "The search"): a name, a part
 * of one or a barcode → catalogue puzzles as the same cards, each a button that chooses it (DecideImportPuzzles
 * checks the pick again).
 *
 * A POST although it changes nothing: the search is prefilled with the puzzle's name as the file writes it, and
 * nothing of a file may stand in an address. CSRF checked. Asked by the chooser's own frame (`Turbo-Frame` header)
 * it answers the page, of which Turbo takes the frame; without the header (no script) it answers the same page with
 * a 422 - a full-page POST never answers 200, Turbo Drive would drop it.
 */
final class SearchImportPuzzleController extends AbstractController
{
    public const string FRAME = 'import-puzzle-search';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportPuzzleScreen $getImportPuzzleScreen,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/puzzle/{importPuzzleId}/search',
        name: 'data_import_puzzle_search',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID, 'importPuzzleId' => Requirement::UUID],
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $importId, string $importPuzzleId): Response
    {
        if ($this->isCsrfTokenValid(ImportsHomeController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $import = $this->getDataImports->byIdOfPlayer($importId, $player->playerId);

        if ($import->status !== DataImportStatus::SettingUp) {
            return $this->redirectToRoute('data_import_detail', ['importId' => $importId]);
        }

        $card = $this->getImportPuzzleScreen->one($importId, $player->playerId, $importPuzzleId);
        $typed = trim(mb_substr($request->request->getString('search'), 0, 200));

        return $this->render(
            'data_import/puzzle_choose.html.twig',
            ImportPuzzleChooserController::parameters(
                $request->request->getString('flow'),
                $request->request->getInt('done'),
                $import,
                $card,
                $this->getImportPuzzleScreen->rowsOf($importId, $player->playerId, $importPuzzleId, ImportPuzzleChooserController::ROWS_SHOWN),
                $typed,
                $this->getImportPuzzleScreen->search($player->playerId, $typed, $card->piecesCount, $card->manufacturerId),
                searchTooShort: GetImportPuzzleScreen::isSearchable($typed) === false,
            ),
            new Response(status: $request->headers->get('Turbo-Frame') === self::FRAME ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY),
        );
    }
}
