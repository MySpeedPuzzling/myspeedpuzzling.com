<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ReviewResults;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\AutoRemovalCanNotBeUndone;
use SpeedPuzzling\Web\Message\UndoAutoRemoval;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Undo" of an automatic removal - the copy comes back (docs/features/duplicate-results.md).
 */
final class UndoAutoRemovalController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/review-results/removed/{removalId}/undo',
        name: 'review_results_undo_removal',
        requirements: ['removalId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $removalId): Response
    {
        if ($this->isCsrfTokenValid(ReviewResultsController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        try {
            $this->messageBus->dispatch(new UndoAutoRemoval($removalId, $player->playerId));
        } catch (HandlerFailedException $exception) {
            if ($exception->getPrevious() instanceof AutoRemovalCanNotBeUndone) {
                $this->addFlash('warning', $this->translator->trans('review_results.flash.undo_failed'));

                return $this->redirectToRoute('review_results');
            }

            throw $exception;
        }

        $this->addFlash('success', $this->translator->trans('review_results.flash.undone'));

        return $this->redirectToRoute('review_results');
    }
}
