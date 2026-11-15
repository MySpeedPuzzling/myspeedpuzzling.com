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
 * How far one group answer of the signed-in player's import is, as JSON - what its page asks every few seconds
 * (data_import_adding_controller.js). Numbers and states only; another player's answer is a 404. One read.
 */
final class ImportGroupAnswerProgressController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetImportAddScreen $getImportAddScreen,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/group-answers/{answerId}/progress',
        name: 'data_import_group_answer_progress',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID, 'answerId' => Requirement::UUID],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(string $importId, string $answerId): JsonResponse
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $answer = $this->getImportAddScreen->groupAnswer($importId, $player->playerId, $answerId);

        return new JsonResponse([
            'id' => $answer['id'],
            'running' => $answer['running'],
            'paused' => false,
            'total' => $answer['total'],
            'done' => $answer['done'],
        ], headers: ['Cache-Control' => 'private, no-store']);
    }
}
