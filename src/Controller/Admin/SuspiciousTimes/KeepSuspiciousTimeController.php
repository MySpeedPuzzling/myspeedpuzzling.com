<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin\SuspiciousTimes;

use SpeedPuzzling\Web\Exceptions\SuspiciousTimeCaseChanged;
use SpeedPuzzling\Web\Message\KeepSolvingTimeSuspicious;
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
 * "Keep it marked" on the "Player replied" tab - the note tells the player why.
 */
#[IsGranted(SuspiciousResultsVoter::REVIEW_SUSPICIOUS_TIMES)]
final class KeepSuspiciousTimeController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: '/admin/time-verification/{caseId}/keep',
        name: 'admin_time_verification_keep',
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

        if ($note === null || $note === false) {
            $this->addFlash('danger', sprintf('Keeping it marked needs a note for the player (at most %d characters) - nothing was saved.', TimeVerificationController::NOTE_MAX_LENGTH));

            return $this->redirectToRoute('admin_time_verification', $back);
        }

        try {
            $this->messageBus->dispatch(new KeepSolvingTimeSuspicious(
                caseId: $caseId,
                decidedById: $moderator->playerId,
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

        $this->addFlash('success', 'Kept marked - the player gets your note.');

        return $this->redirectToRoute('admin_time_verification', $back);
    }
}
