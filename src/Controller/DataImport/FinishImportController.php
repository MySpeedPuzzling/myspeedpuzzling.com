<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Message\FinishImport;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImport\ImportClosingOutcome;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "I'm done with this import" (docs/features/data-import/upload.md "Finished"): POST + CSRF, then back to the
 * import's page, which says what came of it. Another player's import is the handler's 404.
 */
final class FinishImportController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/done',
        name: 'data_import_done',
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

        $outcome = $this->messageBus->dispatch(new FinishImport($importId, $player->playerId))->last(HandledStamp::class)?->getResult();
        assert($outcome instanceof ImportClosingOutcome);

        $this->addFlash(
            $outcome === ImportClosingOutcome::Finished ? 'success' : 'warning',
            $this->translator->trans('data_import.finish.flash.' . $outcome->value),
        );

        return $this->redirectToRoute('data_import_detail', ['importId' => $importId]);
    }
}
