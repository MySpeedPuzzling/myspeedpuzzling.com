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
 * How far one Undo of the signed-in player's import is, as JSON - what its page asks every few seconds
 * (data_import_adding_controller.js). Numbers and states only; another player's Undo is a 404. One read, no write.
 */
final class ImportUndoProgressController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetImportAddScreen $getImportAddScreen,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/undos/{undoId}/progress',
        name: 'data_import_undo_progress_json',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID, 'undoId' => Requirement::UUID],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(string $importId, string $undoId): JsonResponse
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $undo = $this->getImportAddScreen->undo($importId, $player->playerId, $undoId);

        return new JsonResponse([
            'id' => $undo['id'],
            'running' => $undo['running'],
            'paused' => false,
            'total' => $undo['rows'],
            'done' => $undo['done'],
        ], headers: ['Cache-Control' => 'private, no-store']);
    }
}
