<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Message\ReadImportAgain;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Try again" on the page of an import whose file could not be read (docs/features/data-import/reading.md): POST +
 * CSRF, then the import's page, which shows the reading's progress again. Another player's import is a 404.
 */
final class ReadImportAgainController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/read-again',
        name: 'data_import_read_again',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID],
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $importId): Response
    {
        if ($this->isCsrfTokenValid(ImportsHomeController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        // Another player's import is "not found" - the handler's 404 goes on as it is. An import that is not waiting
        // for another try (read meanwhile, being read) simply shows what it is
        $this->messageBus->dispatch(new ReadImportAgain($importId, $player->playerId));

        return $this->redirectToRoute('data_import_detail', ['importId' => $importId]);
    }
}
