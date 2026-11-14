<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImportLimits;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One import of the signed-in player: its file, its state, and the honest line that reading and adding arrive soon
 * (docs/features/data-import/upload.md). Another player's import - or a discarded one - is a 404.
 */
final class ImportDetailController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
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

        return $this->render('data_import/detail.html.twig', [
            'import' => $this->getDataImports->byIdOfPlayer($importId, $player->playerId),
            'keep_days' => DataImportLimits::KEEP_FILE_DAYS,
        ]);
    }
}
