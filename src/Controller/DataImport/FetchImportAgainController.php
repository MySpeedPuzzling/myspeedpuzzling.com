<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Message\FetchImportAgain;
use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImportStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Try again" on the page of an import whose Google sheet was not fetched (docs/features/data-import/google-sheets.md):
 * POST + CSRF, then the import's page, which shows the fetching again. Every try is a request of ours to Google, so
 * it counts against the same limit as a new link. Another player's import is a 404.
 */
final class FetchImportAgainController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private MessageBusInterface $messageBus,
        readonly private RateLimiterFactoryInterface $dataImportGoogleLimiter,
        readonly private TranslatorInterface $translator,
    ) {
    }

    /**
     * @throws DataImportNotFound
     */
    #[Route(
        path: '/{_locale}/import/{importId}/fetch-again',
        name: 'data_import_fetch_again',
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

        // Another player's import is "not found" - before anything is counted. An import that is not waiting for
        // another try (fetched meanwhile, being fetched) simply shows what it is
        $import = $this->getDataImports->byIdOfPlayer($importId, $player->playerId);

        if ($import->status === DataImportStatus::FetchFailed) {
            if ($this->dataImportGoogleLimiter->create($player->playerId)->consume()->isAccepted() === false) {
                $this->addFlash('warning', $this->translator->trans('data_import.refused.google_rate_limited'));
            } else {
                $this->messageBus->dispatch(new FetchImportAgain($importId, $player->playerId));
            }
        }

        return $this->redirectToRoute('data_import_detail', ['importId' => $importId]);
    }
}
