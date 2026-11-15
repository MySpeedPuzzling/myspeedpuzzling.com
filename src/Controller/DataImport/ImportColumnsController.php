<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Results\DataImportSheetOverview;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImportStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Sheets and columns" of the signed-in player's import (docs/features/data-import/columns-screen.md): every sheet of
 * the file as a card - what MySpeedPuzzling takes it for, changeable with one tap, and how its rows fared - the
 * sheets that wait for an answer first. Another player's import is a 404; an import that is not read yet (or is past
 * its set-up) shows its own page. Two statements whatever the number of sheets.
 */
final class ImportColumnsController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/columns',
        name: 'data_import_columns',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(string $importId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $import = $this->getDataImports->byIdOfPlayer($importId, $player->playerId);

        if ($import->status !== DataImportStatus::SettingUp) {
            return $this->redirectToRoute('data_import_detail', ['importId' => $importId]);
        }

        $sheets = $this->getDataImports->sheetsOfPlayer($importId, $player->playerId);

        // Those that wait for the player first, then the file's own order
        usort($sheets, static fn (DataImportSheetOverview $a, DataImportSheetOverview $b): int => [$a->state() !== 'waiting', $a->position] <=> [$b->state() !== 'waiting', $b->position]);

        return $this->render('data_import/columns.html.twig', [
            'import' => $import,
            'sheets' => $sheets,
            'summary' => self::summary($sheets),
        ]);
    }

    /**
     * The import in one line: rows ready, rows that need a look, sheets left out - and whether anything is still
     * worked on by itself.
     *
     * @param list<DataImportSheetOverview> $sheets
     *
     * @return array{ready: int, look: int, left_out: int, waiting: int, working: bool}
     */
    public static function summary(array $sheets): array
    {
        $summary = ['ready' => 0, 'look' => 0, 'left_out' => 0, 'waiting' => 0, 'working' => false];

        foreach ($sheets as $sheet) {
            $state = $sheet->state();
            $summary['left_out'] += $sheet->isLeftOut() && $sheet->failed === false ? 1 : 0;
            $summary['waiting'] += $state === 'waiting' ? 1 : 0;
            $summary['working'] = $summary['working'] || $state === 'recognising' || $state === 'parsing';

            if ($state === 'parsed') {
                $summary['ready'] += $sheet->rowsUnderstood;
                $summary['look'] += $sheet->rowsNeedLook;
            }
        }

        return $summary;
    }
}
