<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImportLimits;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One import of the signed-in player: its file, its state, the reading's live progress, the sheets the file has once
 * it is read - or why it was not (docs/features/data-import/reading.md). Another player's import - or a discarded
 * one - is a 404.
 */
final class ImportDetailController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
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
    public function __invoke(string $importId): Response
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

        return $this->render('data_import/detail.html.twig', [
            'import' => $import,
            'sheets' => $sheets,
            'summary' => ImportColumnsController::summary($sheets),
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
