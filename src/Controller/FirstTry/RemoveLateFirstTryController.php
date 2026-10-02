<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\FirstTry;

use SpeedPuzzling\Web\Message\UnmarkFirstAttempt;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class RemoveLateFirstTryController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/first-try-conflicts/result/{timeId}/remove',
        name: 'first_try_late_remove',
        requirements: ['timeId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $timeId): Response
    {
        if ($this->isCsrfTokenValid(FirstTryConflictsController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        // Somebody else's result answers 403 (CanNotModifyOtherPlayersTime)
        $this->messageBus->dispatch(new UnmarkFirstAttempt($player->playerId, $timeId));

        $this->addFlash('success', $this->translator->trans('first_try.flash.removed_one'));

        return $this->redirectToRoute('review_results');
    }
}
