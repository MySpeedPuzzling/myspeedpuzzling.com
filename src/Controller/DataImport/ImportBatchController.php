<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportAddScreen;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One "Add" of the signed-in player's import (docs/features/data-import/adding-screens.md "Progress and done"):
 * while the worker goes through its rows the live line ("Adding… 320 of 1,240" - safe to leave), then what came of
 * it: added, already yours, could not be added by reason - each with a plain sentence - and the ways on: the
 * profile, the rows that could not be added, Undo. Another player's batch, or a batch of another import, is a 404.
 * Three statements, whatever the batch's size.
 */
final class ImportBatchController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportAddScreen $getImportAddScreen,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/batches/{batchId}',
        name: 'data_import_batch',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID, 'batchId' => Requirement::UUID],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(string $importId, string $batchId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $import = $this->getDataImports->byIdOfPlayer($importId, $player->playerId);
        $batch = $this->getImportAddScreen->batch($importId, $player->playerId, $batchId);

        return $this->render('data_import/batch.html.twig', [
            'import' => $import,
            'batch' => $batch,
            // Why rows could not be added, and how rows were already the player's - what explains the numbers
            'outcomes' => $batch['notAdded'] + $batch['alreadyYours'] > 0
                ? $this->getImportAddScreen->outcomesOfBatch($importId, $player->playerId, $batchId)
                : ['reasons' => [], 'alreadyYours' => []],
        ]);
    }
}
