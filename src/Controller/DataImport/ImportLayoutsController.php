<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Query\GetImportLayouts;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Your saved layouts" (docs/features/data-import/layouts.md): the signed-in player's own remembered layouts - the
 * name, when it was last used, how often - each with Rename and Forget. One statement.
 */
final class ImportLayoutsController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetImportLayouts $getImportLayouts,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/layouts',
        name: 'data_import_layouts',
        requirements: ['_locale' => ImportsHomeController::LOCALES],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        return $this->render('data_import/layouts.html.twig', [
            'layouts' => $this->getImportLayouts->ofPlayer($player->playerId),
            // Where the player came from (an import's "Sheets and columns") - checked where it is used
            'back' => ReturnUrl::tryFrom($request->query->getString('return'))?->path,
        ]);
    }
}
