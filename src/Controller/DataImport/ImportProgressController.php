<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * How far the signed-in player's import is, as JSON (docs/features/data-import/reading.md "Progress"): what the
 * import's page asks every few seconds when no event of the player's private topic arrives, and once more when a
 * state changes. Numbers and states only; another player's import is a 404. One indexed read, no write.
 */
final class ImportProgressController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/progress',
        name: 'data_import_progress',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(string $importId): JsonResponse
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $progress = $this->getDataImports->progressOfPlayer($importId, $player->playerId);

        return new JsonResponse(
            [
                ...$progress->toArray(),
                // Where the page goes when nothing moves any more: itself (now with the sheets, or with why the file
                // was not read) - or the import the player already has of the very same file
                'url' => $this->generateUrl('data_import_detail', ['importId' => $progress->sameFileAsImportId ?? $progress->importId]),
            ],
            headers: ['Cache-Control' => 'private, no-store'],
        );
    }
}
