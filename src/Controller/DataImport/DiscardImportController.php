<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Exceptions\ImportCanNotBeDiscarded;
use SpeedPuzzling\Web\Message\DiscardImport;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Discard an import: its file and rows go (DiscardImportHandler). POST + CSRF, then back to Imports home.
 */
final class DiscardImportController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/discard',
        name: 'data_import_discard',
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

        // Another player's import is "not found" - the handler's 404 goes on as it is
        try {
            $this->messageBus->dispatch(new DiscardImport($importId, $player->playerId));
        } catch (ImportCanNotBeDiscarded) {
            // An import that added something is kept (its page does not offer Discard; a page that was open before
            // the "Add" may still send it): said plainly, with the way to take back what it added
            $this->addFlash('info', $this->translator->trans('data_import.detail.kept_title'));

            return $this->redirectToRoute('data_import_detail', ['importId' => $importId]);
        }

        $this->addFlash('success', $this->translator->trans('data_import.flash.discarded'));

        return $this->redirectToRoute('data_imports');
    }
}
