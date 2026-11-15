<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Services\DataImport\ImportToFixPage;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImportStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "To fix" of one import (docs/features/data-import/pairing-screens.md "To fix"): the rows that need a look, grouped
 * by what is unclear and the value as the sheet has it - one answer for all rows of a value, or "Leave these rows
 * out". The page number is the only thing in the address - never a cell's value. Another player's import is a 404.
 * Four statements whatever the import's size.
 */
final class ImportRowsToFixController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private ImportToFixPage $page,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/to-fix',
        name: 'data_import_to_fix',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $importId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $parameters = $this->page->parameters($importId, $player->playerId, $request->query->getInt('page', 1));

        if ($parameters['import']->status !== DataImportStatus::SettingUp) {
            return $this->redirectToRoute('data_import_detail', ['importId' => $importId]);
        }

        return $this->render('data_import/to_fix.html.twig', $parameters);
    }
}
