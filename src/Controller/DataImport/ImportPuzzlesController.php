<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportAddScreen;
use SpeedPuzzling\Web\Query\GetImportPuzzleScreen;
use SpeedPuzzling\Web\Results\DataImportProgress;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The Puzzles summary of one import (docs/features/data-import/pairing-screens.md "The summary"): how many different
 * puzzles the file holds and the groups the pairing put them in, each a big row with its counts - and the next thing
 * that needs the player - and what is ready to be added. It follows a running pairing by itself. Another player's
 * import is a 404. Four statements whatever the import's size.
 */
final class ImportPuzzlesController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportPuzzleScreen $getImportPuzzleScreen,
        readonly private GetImportAddScreen $getImportAddScreen,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/puzzles',
        name: 'data_import_puzzles',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(string $importId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $import = $this->getDataImports->byIdOfPlayer($importId, $player->playerId);
        $overview = $this->getImportPuzzleScreen->overview($importId, $player->playerId);

        if ($overview->beingSetUp === false) {
            return $this->redirectToRoute('data_import_detail', ['importId' => $importId]);
        }

        $progress = $this->getDataImports->progressOfPlayer($importId, $player->playerId);

        return $this->render('data_import/puzzles.html.twig', [
            'import' => $import,
            'overview' => $overview,
            // What is ready to be added (docs/features/data-import/adding-screens.md "Ready to add") - one statement
            'add' => $this->getImportAddScreen->summary($importId, $player->playerId),
            'live' => self::live($progress),
            // The sheets whose columns the player has not checked yet: nothing of them is matched (ImportCheckFirst) -
            // the page says so and leads to the check, instead of "0 of 0 puzzles". Read with the progress: no
            // statement of its own
            'held' => $progress->sheetsHeld,
            'held_sheet_id' => $progress->firstHeldSheetId,
        ]);
    }

    /**
     * What the live line of a puzzles page starts from (data_import_setup_controller.js in its pairing mode):
     * whether rows are still read or puzzles still paired, and the state the page was rendered with.
     *
     * @return array{working: bool, revision: string, searching: int, puzzles: int}
     */
    public static function live(DataImportProgress $progress): array
    {
        return [
            'working' => $progress->sheetsWorking > 0 || $progress->pairing['running'],
            'revision' => $progress->puzzlesRevision(),
            'searching' => $progress->pairing['searching'],
            'puzzles' => $progress->pairing['puzzles'],
        ];
    }
}
