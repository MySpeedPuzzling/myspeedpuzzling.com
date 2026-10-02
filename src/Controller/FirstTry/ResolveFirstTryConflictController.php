<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\FirstTry;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\FirstTryConflictChanged;
use SpeedPuzzling\Web\Message\ResolveFirstTryConflict;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ResolveFirstTryConflictController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/first-try-conflicts/{puzzleId}/resolve',
        name: 'first_try_conflict_resolve',
        requirements: ['puzzleId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $puzzleId): Response
    {
        if ($this->isCsrfTokenValid(FirstTryConflictsController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $keep = $request->request->getString('keep');

        // Nothing chosen: the radios are required, so only a hand-made request gets here
        if ($keep !== 'none' && Uuid::isValid($keep) === false) {
            return $this->redirectToRoute('review_results');
        }

        try {
            $this->messageBus->dispatch(new ResolveFirstTryConflict($player->playerId, $puzzleId, $keep === 'none' ? null : $keep));
        } catch (HandlerFailedException $exception) {
            if ($exception->getPrevious() instanceof FirstTryConflictChanged) {
                $this->addFlash('warning', $this->translator->trans('first_try.flash.changed'));

                return $this->redirectToRoute('review_results');
            }

            throw $exception;
        }

        $this->addFlash('success', $this->translator->trans($keep === 'none' ? 'first_try.flash.removed_all' : 'first_try.flash.kept'));

        return $this->redirectToRoute('review_results');
    }
}
