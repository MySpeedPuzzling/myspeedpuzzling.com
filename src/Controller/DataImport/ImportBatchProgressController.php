<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Query\GetImportAddScreen;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * How far one "Add" of the signed-in player's import is, as JSON - what its page asks every few seconds when no
 * event of the player's private topic arrives (data_import_adding_controller.js). Numbers and states only; another
 * player's batch is a 404. One read, no write.
 */
final class ImportBatchProgressController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetImportAddScreen $getImportAddScreen,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/batches/{batchId}/progress',
        name: 'data_import_batch_progress',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID, 'batchId' => Requirement::UUID],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(string $importId, string $batchId): JsonResponse
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $batch = $this->getImportAddScreen->batch($importId, $player->playerId, $batchId);

        return new JsonResponse([
            'id' => $batch['id'],
            'running' => $batch['running'],
            'paused' => $batch['paused'],
            'total' => $batch['rows'],
            'done' => $batch['done'],
        ], headers: ['Cache-Control' => 'private, no-store']);
    }
}
