<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\SyncEventResultsSheet;
use SpeedPuzzling\Web\Query\GetEventResultsSheets;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Reads a results sheet at once instead of waiting for the hourly sync, answers what became of its tabs.
 */
final class SyncEventResultsSheetController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly GetEventResultsSheets $getEventResultsSheets,
    ) {
    }

    #[Route(
        path: '/internal-api/event-results-sheets/{sheetId}/sync',
        requirements: ['sheetId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(string $sheetId): JsonResponse
    {
        // 404 for an unknown sheet before anything is read
        $this->getEventResultsSheets->detail($sheetId);

        $this->messageBus->dispatch(new SyncEventResultsSheet($sheetId));

        return new JsonResponse($this->getEventResultsSheets->detail($sheetId));
    }
}
