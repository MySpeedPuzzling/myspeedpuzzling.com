<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ReviewResults;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\ReplySuspiciousTimeIsCorrect;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "The time is correct" on a result awaiting verification, with an optional message (≤ 500 characters) -
 * docs/features/suspicious-time-review.md, "Where they see it". Somebody else's case is not found.
 */
final class ReplySuspiciousTimeIsCorrectController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/review-results/verification/{caseId}/correct',
        name: 'review_results_suspicious_correct',
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

        $text = trim($request->request->getString('message'));

        $envelope = $this->messageBus->dispatch(new ReplySuspiciousTimeIsCorrect($caseId, $player->playerId, $text !== '' ? $text : null));
        $recorded = $envelope->last(HandledStamp::class)?->getResult();

        if ($recorded === true) {
            $this->addFlash('success', $this->translator->trans('suspicious_time.flash.replied'));
        } else {
            $this->addFlash('info', $this->translator->trans('suspicious_time.flash.already_replied'));
        }

        return $this->redirect($this->generateUrl('review_results') . '#awaiting-verification');
    }
}
