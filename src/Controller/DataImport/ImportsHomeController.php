<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportAddScreen;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Imports home: the signed-in player's spreadsheets, the newest first (docs/features/data-import/upload.md). Nobody
 * else's imports are ever here: every read takes the signed-in player's id.
 */
final class ImportsHomeController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'data_import';
    public const string LOCALES = 'en|cs|de|es|fr|ja';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportAddScreen $getImportAddScreen,
    ) {
    }

    #[Route(
        path: '/{_locale}/import',
        name: 'data_imports',
        requirements: ['_locale' => self::LOCALES],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        return $this->render('data_import/index.html.twig', [
            'imports' => $this->getDataImports->ofPlayer($player->playerId),
            // What each import added to the profile, and whether one is adding right now - one statement for all
            'added' => $this->getImportAddScreen->addedByImports($player->playerId),
        ]);
    }
}
