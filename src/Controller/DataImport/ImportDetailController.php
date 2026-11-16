<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportAddScreen;
use SpeedPuzzling\Web\Query\GetImportPairing;
use SpeedPuzzling\Web\Query\GetImportPeople;
use SpeedPuzzling\Web\Services\DataImport\ImportFlow;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImportLimits;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Where you are" - one import of the signed-in player (docs/features/data-import/guided-flow.md §5.3): the five
 * steps of its flow with one status sentence each, the ONE next action, and - while the file is not read - the
 * reading's live progress or why it could not be read (docs/features/data-import/reading.md). Another player's
 * import - or a discarded one - is a 404.
 *
 * `?after=<screen>` is the "Continue" of the flow's screens: it answers with a redirect to the next screen that has
 * something waiting (ImportFlow::nextAfter()) - worked out here, from what this page loads anyway, so no screen
 * needs a statement more to know where its Continue leads. Nothing is stored about where the player is.
 */
final class ImportDetailController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportPairing $getImportPairing,
        readonly private GetImportAddScreen $getImportAddScreen,
        readonly private GetImportPeople $getImportPeople,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}',
        name: 'data_import_detail',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $importId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        try {
            $import = $this->getDataImports->byIdOfPlayer($importId, $player->playerId);
        } catch (DataImportNotFound $notFound) {
            // An import closed because the player already has the very same file: its page is that import's
            // (docs/features/data-import/reading.md "The same file again")
            try {
                $sameFile = $this->getDataImports->progressOfPlayer($importId, $player->playerId)->sameFileAsImportId;
            } catch (DataImportNotFound) {
                throw $notFound;
            }

            if ($sameFile === null) {
                throw $notFound;
            }

            $this->addFlash('info', $this->translator->trans('data_import.flash.same_file'));

            return $this->redirectToRoute('data_import_detail', ['importId' => $sameFile]);
        }

        $sheets = $this->getDataImports->sheetsOfPlayer($importId, $player->playerId);

        // Its distinct puzzles and how far their pairing with the catalogue is - one statement, read only
        $pairing = $this->getImportPairing->summary($importId);
        // What is ready to be added, what is not and why, and what the import added so far - one statement
        $add = $this->getImportAddScreen->summary($importId, $player->playerId);
        // The people the file names - one statement: step 3 says "Nobody else is named." or the exact counts
        $flow = ImportFlow::of($import, $sheets, $pairing, $add, $this->getImportPeople->counts($importId, $player->playerId));

        $after = $request->query->getString('after');

        if ($after !== '') {
            $next = $flow->nextAfter($after);

            return $this->redirectToRoute($next['route'], $next['parameters']);
        }

        // What the page's live line starts from: it follows the reading of the rows and the pairing by itself - but
        // not a pairing that has nothing to pair because every puzzle of the file waits for the columns check
        // (ImportCheckFirst): no "Matching your puzzles…" there
        $progress = $this->getDataImports->progressOfPlayer($importId, $player->playerId);
        $live = ImportPuzzlesController::live($progress);
        $followsPairing = $progress->sheetsHeld === 0 || $progress->pairing['puzzles'] > 0;

        if ($followsPairing === false) {
            $live = [...$live, 'working' => $progress->sheetsWorking > 0, 'revision' => $progress->revision];
        }

        return $this->render('data_import/detail.html.twig', [
            'import' => $import,
            'sheets' => $sheets,
            'import_flow' => $flow,
            'add' => $add,
            // A finished import can be gone on with while its rows still hold what the spreadsheet said
            'can_go_on' => $add->wiped === false,
            'live' => $live,
            'live_pairing' => $followsPairing,
            'keep_days' => DataImportLimits::KEEP_FILE_DAYS,
            'limits' => [
                '%megabytes%' => intdiv(DataImportLimits::MAX_FILE_BYTES, 1024 * 1024),
                '%sheets%' => DataImportLimits::MAX_SHEETS,
                '%rows%' => number_format(DataImportLimits::MAX_ROWS, 0, '.', ' '),
                '%cells%' => number_format(DataImportLimits::MAX_CELLS, 0, '.', ' '),
            ],
        ]);
    }
}
