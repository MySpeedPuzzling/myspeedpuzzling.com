<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Value\DataImportLimits;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Bring your spreadsheet over": the public guide of the spreadsheet import
 * (docs/features/data-import/entry-points.md). Static text - it reads nothing, for a guest and a player alike.
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
        return $this->render('data_import/guide.html.twig', [
            'keep_days' => DataImportLimits::KEEP_FILE_DAYS,
        ]);
    }
}
