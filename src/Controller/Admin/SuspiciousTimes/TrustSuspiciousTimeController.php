<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin\SuspiciousTimes;

use SpeedPuzzling\Web\Exceptions\SuspiciousTimeCaseChanged;
use SpeedPuzzling\Web\Message\TrustSolvingTime;
use SpeedPuzzling\Web\Security\SuspiciousResultsVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\SuspiciousTimeCaseStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Looks fine" on a pending case, and on a marked one (= unmark, also the answer to a player's reply).
 */
#[IsGranted(SuspiciousResultsVoter::REVIEW_SUSPICIOUS_TIMES)]
final class TrustSuspiciousTimeController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: '/admin/time-verification/{caseId}/trust',
        name: 'admin_time_verification_trust',
        requirements: ['caseId' => TimeVerificationController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $caseId): Response
    {
        if ($this->isCsrfTokenValid('time-verification-' . $caseId, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $moderator = $this->retrieveLoggedUserProfile->getProfile();
        assert($moderator !== null);

        $back = TimeVerificationController::returnParameters($request);
        $note = TimeVerificationController::note($request);

        if ($note === false) {
            $this->addFlash('danger', sprintf('The note can have at most %d characters - nothing was saved.', TimeVerificationController::NOTE_MAX_LENGTH));

            return $this->redirectToRoute('admin_time_verification', $back);
        }

        $seenStatus = SuspiciousTimeCaseStatus::tryFrom($request->request->getString('status'));

        // Only a hand-made request comes without the status the page showed
        if ($seenStatus === null) {
            return $this->redirectToRoute('admin_time_verification', $back);
        }

        try {
            $this->messageBus->dispatch(new TrustSolvingTime(
                caseId: $caseId,
                decidedById: $moderator->playerId,
                note: $note,
                seenFingerprint: $request->request->getString('fingerprint'),
                seenStatus: $seenStatus,
            ));
        } catch (HandlerFailedException $exception) {
            if ($exception->getPrevious() instanceof SuspiciousTimeCaseChanged) {
                $this->addFlash('warning', 'This case changed meanwhile - nothing was saved. Have a look at it again.');

                return $this->redirectToRoute('admin_time_verification', $back);
            }

            throw $exception;
        }

        $this->addFlash('success', $seenStatus === SuspiciousTimeCaseStatus::Marked
            ? 'Unmarked - the time counts again.'
            : 'Verified fine - this entry will not be raised again.');

        return $this->redirectToRoute('admin_time_verification', $back);
    }
}
