<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ReviewResults;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\DuplicateCaseChanged;
use SpeedPuzzling\Web\Message\ConfirmDuplicateIsReal;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DuplicateResolvedVia;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Both/All are real" on a set of copies - from the review page or the recap (`via=recap`, back to the result it
 * was opened from). `copies[]` = the copies the page showed; a set that changed since is refused.
 */
final class ConfirmDuplicateIsRealController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/review-results/duplicates/{caseId}/both-real',
        name: 'review_results_both_real',
        requirements: ['caseId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $caseId): Response
    {
        if ($this->isCsrfTokenValid(ReviewResultsController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $recapTimeId = $request->request->getString('time');
        $via = $request->request->getString('via') === 'recap' && Uuid::isValid($recapTimeId)
            ? DuplicateResolvedVia::Recap
            : DuplicateResolvedVia::ReviewPage;

        $copies = ReviewResultsController::shownCopies($request);

        try {
            $this->messageBus->dispatch(new ConfirmDuplicateIsReal($caseId, $player->playerId, $via, $copies));
        } catch (HandlerFailedException $exception) {
            if ($exception->getPrevious() instanceof DuplicateCaseChanged) {
                $this->addFlash('warning', $this->translator->trans('review_results.flash.changed'));

                return $this->redirectToRoute('review_results');
            }

            throw $exception;
        }

        $this->addFlash('success', $this->translator->trans('review_results.flash.both_real', ['%count%' => max(2, count($copies))]));

        if ($via === DuplicateResolvedVia::Recap) {
            return $this->redirectToRoute('added_time_recap', ['timeId' => $recapTimeId]);
        }

        return $this->redirectToRoute('review_results');
    }
}
