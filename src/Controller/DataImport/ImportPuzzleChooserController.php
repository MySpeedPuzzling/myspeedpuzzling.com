<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportPuzzleScreen;
use SpeedPuzzling\Web\Results\DataImportOverview;
use SpeedPuzzling\Web\Results\ImportCataloguePuzzle;
use SpeedPuzzling\Web\Results\ImportPuzzleCard;
use SpeedPuzzling\Web\Results\ImportSheetRow;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImportStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One puzzle of an import on a screen of its own - the chooser (docs/features/data-import/pairing-screens.md "The
 * chooser"): what the file says, a few of its rows, the catalogue puzzles it could be as large picture cards (a tap
 * chooses), the search, "None of these" and "Skip". Reachable from every card of a list; inside "Do the next 20"
 * (`flow`, `done`) a choice leads on to the next puzzle that waits. Another player's import, or a puzzle of another
 * import, is a 404. Three statements.
 */
final class ImportPuzzleChooserController extends AbstractController
{
    public const int ROWS_SHOWN = 3;

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportPuzzleScreen $getImportPuzzleScreen,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/puzzle/{importPuzzleId}',
        name: 'data_import_puzzle_choose',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID, 'importPuzzleId' => Requirement::UUID],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $importId, string $importPuzzleId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $import = $this->getDataImports->byIdOfPlayer($importId, $player->playerId);

        if ($import->status !== DataImportStatus::SettingUp) {
            return $this->redirectToRoute('data_import_detail', ['importId' => $importId]);
        }

        $card = $this->getImportPuzzleScreen->one($importId, $player->playerId, $importPuzzleId);
        // "Your file says 500 pieces, this puzzle has 1,000 - choose it anyway?" for a puzzle tapped elsewhere
        // (an answer shown in place leads here with it): an id, checked like every puzzle that is offered
        $ask = $request->query->getString('ask');
        $asked = Uuid::isValid($ask) && $card->isDecided() === false
            ? $this->getImportPuzzleScreen->offered($player->playerId, $ask, $card->piecesCount, $card->manufacturerId)
            : null;

        return $this->render('data_import/puzzle_choose.html.twig', self::parameters(
            $request->query->getString('flow'),
            $request->query->getInt('done'),
            $import,
            $card,
            $this->getImportPuzzleScreen->rowsOf($importId, $player->playerId, $importPuzzleId, self::ROWS_SHOWN),
            null,
            null,
            askOtherPieces: $asked !== null && $asked->piecesDiffer ? $asked : null,
            last: $request->query->getString('last'),
        ));
    }

    /**
     * @param list<ImportSheetRow> $rows
     * @param null|list<ImportCataloguePuzzle> $found what the player's search found - null: nothing was searched
     * @param null|ImportCataloguePuzzle $askOtherPieces the puzzle of another piece count the player tapped - asked about once more
     * @param string $last the puzzle decided just before, as the address says it
     *
     * @return array<string, mixed>
     */
    public static function parameters(
        string $flow,
        int $done,
        DataImportOverview $import,
        ImportPuzzleCard $card,
        array $rows,
        null|string $searched,
        null|array $found,
        bool $searchTooShort = false,
        null|ImportCataloguePuzzle $askOtherPieces = null,
        string $last = '',
    ): array {
        $flow = in_array($flow, GetImportPuzzleScreen::GROUPS, true) ? $flow : null;

        return [
            'import' => $import,
            'card' => $card,
            'rows' => $rows,
            'flow' => $flow,
            'done' => $flow === null ? 0 : max(0, $done),
            'portion' => NextImportPuzzleController::PORTION,
            'searched' => $searched,
            'found' => $found,
            'search_too_short' => $searchTooShort,
            // "Your file says 500 pieces, this puzzle has 1,000 - choose it anyway?" (DecideImportPuzzlesController)
            'ask_other_pieces' => $askOtherPieces,
            // The puzzle decided just before in this round: "Undo the last one" leads to it (an id - nothing else)
            'last' => $flow !== null && Uuid::isValid($last) && strtolower($last) !== strtolower($card->importPuzzleId) ? strtolower($last) : null,
        ];
    }
}
