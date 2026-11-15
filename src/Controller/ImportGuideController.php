<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\ImportGuideSample;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "How the import works": the public guide of the spreadsheet import - one small sample spreadsheet followed through
 * the five steps, with the examples as real HTML (docs/features/data-import/guided-flow.md §13,
 * entry-points.md). Fixed data - it reads nothing, for a guest and a player alike.
 */
final class ImportGuideController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/import-tabulky',
            'en' => '/en/import-your-spreadsheet',
            'es' => '/es/importar-hoja-de-calculo',
            'ja' => '/ja/スプレッドシートをインポート',
            'fr' => '/fr/importer-un-tableur',
            'de' => '/de/tabelle-importieren',
        ],
        name: 'import_guide',
        methods: ['GET'],
    )]
    public function __invoke(): Response
    {
        return $this->render('data_import/guide/index.html.twig', [
            'keep_days' => DataImportLimits::KEEP_FILE_DAYS,
            'daily_limit' => DataImportLimits::MAX_RESULTS_ADDED_PER_DAY,
            'sample' => ImportGuideSample::rows(),
        ]);
    }
}
