<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin\SuspiciousTimes;

use SpeedPuzzling\Web\Exceptions\SuspiciousTimeCaseChanged;
use SpeedPuzzling\Web\Message\MarkSolvingTimeSuspicious;
use SpeedPuzzling\Web\Security\SuspiciousResultsVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Needs verification" on a pending case: the ticked reasons and the note are what the player reads.
 */
#[IsGranted(SuspiciousResultsVoter::REVIEW_SUSPICIOUS_TIMES)]
final class MarkSuspiciousTimeController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: '/admin/time-verification/{caseId}/mark',
        name: 'admin_time_verification_mark',
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
            $this->addFlash('danger', sprintf('The note for the player can have at most %d characters - nothing was saved.', TimeVerificationController::NOTE_MAX_LENGTH));

            return $this->redirectToRoute('admin_time_verification', $back);
        }

        $reasonCodes = array_values(array_filter(
            $request->request->all('reasons'),
            static fn (mixed $code): bool => is_string($code),
        ));

        try {
            $this->messageBus->dispatch(new MarkSolvingTimeSuspicious(
                caseId: $caseId,
                decidedById: $moderator->playerId,
                reasonCodes: $reasonCodes,
                note: $note,
                seenFingerprint: $request->request->getString('fingerprint'),
            ));
        } catch (HandlerFailedException $exception) {
            if ($exception->getPrevious() instanceof SuspiciousTimeCaseChanged) {
                $this->addFlash('warning', 'This case changed meanwhile - nothing was saved. Have a look at it again.');

                return $this->redirectToRoute('admin_time_verification', $back);
            }

            throw $exception;
        }

        $this->addFlash('success', 'Marked as needing verification. The time no longer counts; the player is told with the next run.');

        return $this->redirectToRoute('admin_time_verification', $back);
    }
}
