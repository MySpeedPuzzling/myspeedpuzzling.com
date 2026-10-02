<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ReviewResults;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\DuplicateCaseChanged;
use SpeedPuzzling\Web\Message\KeepDuplicateCopy;
use SpeedPuzzling\Web\Results\DuplicateCopiesDeleted;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DuplicateResolvedVia;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Keep this one" / "Delete my copy" on a set of copies - from the review page or the recap (`via=recap`, back to
 * the kept result). `copies[]` = the copies the page showed; a set that changed since is refused.
 */
final class KeepDuplicateCopyController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/review-results/duplicates/{caseId}/keep',
        name: 'review_results_keep_copy',
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

        $keep = $request->request->getString('keep');
        $via = $request->request->getString('via') === 'recap' ? DuplicateResolvedVia::Recap : DuplicateResolvedVia::ReviewPage;

        // Only a hand-made request gets here without a copy to keep
        if (Uuid::isValid($keep) === false) {
            return $this->redirectToRoute('review_results');
        }

        try {
            $envelope = $this->messageBus->dispatch(new KeepDuplicateCopy($caseId, $keep, $player->playerId, $via, ReviewResultsController::shownCopies($request)));
        } catch (HandlerFailedException $exception) {
            if ($exception->getPrevious() instanceof DuplicateCaseChanged) {
                $this->addFlash('warning', $this->translator->trans('review_results.flash.changed'));

                return $this->redirectToRoute('review_results');
            }

            throw $exception;
        }

        $outcome = $envelope->last(HandledStamp::class)?->getResult();
        assert($outcome instanceof DuplicateCopiesDeleted);

        $this->addFlash('success', $this->translator->trans('review_results.flash.copy_deleted', ['%count%' => $outcome->deleted]));

        if ($outcome->firstTryLeftOff) {
            $this->addFlash('warning', $this->translator->trans('review_results.flash.first_try_left_off'));
        }

        if ($via === DuplicateResolvedVia::Recap) {
            return $this->redirectToRoute('added_time_recap', ['timeId' => $keep]);
        }

        return $this->redirectToRoute('review_results');
    }
}
