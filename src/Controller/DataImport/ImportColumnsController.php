<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportLayouts;
use SpeedPuzzling\Web\Query\GetImportSheetScreen;
use SpeedPuzzling\Web\Results\DataImportSheetOverview;
use SpeedPuzzling\Web\Services\DataImport\ImportSheetScreen;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImportStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Step 2 of the import's flow, "Check" (docs/features/data-import/guided-flow.md §5.4): only what needs the player.
 *
 * - While a sheet asks something: **one question a screen**, across all sheets - the sheet with the most rows
 *   first - with the player's own values read each way. A tap on an answer saves it (ConfirmImportSheetController,
 *   the sheet's own form and message) and comes back here for the next one; "Later" (`?q=`, the place in the row of
 *   questions - a number, nothing of a file) leaves a question for another time.
 * - Then the summary - the only screen when nothing is asked: ONE sentence per sheet of what its rows are added as,
 *   the sheets we are not sure about first, the sheets that are not imported as one folded line, and ONE primary:
 *   "Looks right - continue". Everything else about a sheet is behind "Change": the sheet's own page.
 *
 * Another player's import is a 404; an import that is not read yet (or is past its set-up) shows its own page.
 * Four statements whatever the number of sheets (the import, its sheets - one statement for all of them, with what
 * the sentences need - the live line's start, the number of column set-ups) - three on a question's screen (the
 * import, its sheets, the one sheet that asks).
 */
final class ImportColumnsController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportLayouts $getImportLayouts,
        readonly private GetImportSheetScreen $getImportSheetScreen,
        readonly private ImportSheetScreen $sheetScreen,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/columns',
        name: 'data_import_columns',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $importId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $import = $this->getDataImports->byIdOfPlayer($importId, $player->playerId);

        if ($import->status !== DataImportStatus::SettingUp) {
            return $this->redirectToRoute('data_import_detail', ['importId' => $importId]);
        }

        $sheets = $this->getDataImports->sheetsOfPlayer($importId, $player->playerId);

        // ---- The questions, one a screen: the sheet with the most rows first ----
        $asking = array_values(array_filter($sheets, static fn (DataImportSheetOverview $sheet): bool => $sheet->asksThePlayer()));
        usort($asking, static fn (DataImportSheetOverview $a, DataImportSheetOverview $b): int => [-$a->rows, $a->position] <=> [-$b->rows, $b->position]);
        $questions = array_sum(array_map(static fn (DataImportSheetOverview $sheet): int => $sheet->questions, $asking));
        $place = max(0, $request->query->getInt('q'));
        $firstQuestionOf = [];
        $before = 0;
        $asked = null;

        foreach ($asking as $sheet) {
            $firstQuestionOf[$sheet->sheetId] = $before;

            if ($asked === null && $place < $before + $sheet->questions) {
                $asked = ['sheet' => $sheet, 'index' => $place - $before];
            }

            $before += $sheet->questions;
        }

        if ($asked !== null && $request->query->has('summary') === false) {
            $detail = $this->getImportSheetScreen->sheet($importId, $asked['sheet']->sheetId, $player->playerId);
            $view = $this->sheetScreen->view($detail, []);
            $question = is_array($view['questions']) ? ($view['questions'][$asked['index']] ?? null) : null;

            if (is_array($question)) {
                return $this->render('data_import/columns.html.twig', [
                    'import' => $import,
                    'question' => $question,
                    'question_sheet' => $asked['sheet'],
                    'question_number' => $place + 1,
                    'question_total' => max($questions, $place + 1),
                    'question_place' => $place,
                    'sheet_partner' => $view['sheet_partner'] ?? '',
                    // "Later": the next question - after the last one, the summary
                    'later' => $place + 1 < $questions ? ['q' => $place + 1] : ['summary' => 1],
                ]);
            }
        }

        // ---- The summary ----
        $shown = array_values(array_filter($sheets, static fn (DataImportSheetOverview $sheet): bool => $sheet->isLeftOut() === false || $sheet->failed || $sheet->isUnsure()));
        // What needs a look first: a question, a fault of ours, something we are not sure about - then the file's order
        usort($shown, static fn (DataImportSheetOverview $a, DataImportSheetOverview $b): int => [self::needsNothing($a), $a->position] <=> [self::needsNothing($b), $b->position]);
        // The sheets "Continue" leaves for later: we are not sure about them, or their reading went wrong on our side
        $unsure = array_values(array_filter($shown, static fn (DataImportSheetOverview $sheet): bool => ($sheet->isUnsure() || $sheet->failed) && $sheet->asksThePlayer() === false));

        // How dates and times are read, each reading once for the whole file
        $readings = [];

        foreach ($shown as $sheet) {
            foreach ($sheet->isLeftOut() ? [] : $sheet->readings as $reading) {
                $readings[$reading['type'] . ':' . $reading['value']] = $reading;
            }
        }

        $progress = $this->getDataImports->progressOfPlayer($importId, $player->playerId);

        return $this->render('data_import/columns.html.twig', [
            'import' => $import,
            'question' => null,
            'sheets' => $shown,
            // The file's own table of sheets, folded at the end
            'all_sheets' => $sheets,
            'not_imported' => array_values(array_filter($sheets, static fn (DataImportSheetOverview $sheet): bool => $sheet->isLeftOut() && $sheet->failed === false && $sheet->isUnsure() === false)),
            'unsure' => $unsure,
            'questions' => $questions,
            'first_question_of' => $firstQuestionOf,
            'readings' => array_values($readings),
            'summary' => self::summary($sheets),
            // What the live line compares the progress with from its first moment (data_import_setup_controller.js)
            'revision' => $progress->revision,
            'saved_layouts' => $this->getImportLayouts->countOfPlayer($player->playerId),
        ]);
    }

    /**
     * 1 for a sheet whose line has a tick, 0 for one that comes first: it asks, it failed, or we are not sure.
     */
    private static function needsNothing(DataImportSheetOverview $sheet): int
    {
        return $sheet->asksThePlayer() || $sheet->failed || $sheet->isUnsure() ? 0 : 1;
    }

    /**
     * The import in one line: rows ready, rows that need a look, rows that wait for an answer, sheets left out - and
     * whether anything is still worked on by itself.
     *
     * @param list<DataImportSheetOverview> $sheets
     *
     * @return array{ready: int, look: int, waiting: int, left_out: int, working: bool}
     */
    public static function summary(array $sheets): array
    {
        $summary = ['ready' => 0, 'look' => 0, 'waiting' => 0, 'left_out' => 0, 'working' => false];

        foreach ($sheets as $sheet) {
            $state = $sheet->state();
            $summary['left_out'] += $sheet->isLeftOut() && $sheet->failed === false ? 1 : 0;
            $summary['working'] = $summary['working'] || $state === 'recognising' || $state === 'parsing';

            if ($state === 'parsed' || $state === 'waiting') {
                $summary['ready'] += $sheet->rowsUnderstood;
                $summary['look'] += $sheet->rowsNeedLook;
                $summary['waiting'] += $sheet->rowsWaiting;
            }
        }

        return $summary;
    }
}
