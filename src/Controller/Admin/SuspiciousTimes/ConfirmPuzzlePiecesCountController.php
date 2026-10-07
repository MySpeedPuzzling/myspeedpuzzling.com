<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin\SuspiciousTimes;

use SpeedPuzzling\Web\Exceptions\PuzzleIsStillSecret;
use SpeedPuzzling\Web\Exceptions\SuspiciousTimeCaseChanged;
use SpeedPuzzling\Web\Message\ConfirmPuzzlePiecesCount;
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
 * "Piece count is right" on a puzzle card: its cases go on one by one.
 */
#[IsGranted(SuspiciousResultsVoter::REVIEW_SUSPICIOUS_TIMES)]
final class ConfirmPuzzlePiecesCountController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: '/admin/time-verification/puzzles/{puzzleId}/pieces-count-right',
        name: 'admin_time_verification_confirm_pieces',
        requirements: ['puzzleId' => TimeVerificationController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $puzzleId): Response
    {
        if ($this->isCsrfTokenValid('time-verification-puzzle-' . $puzzleId, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $moderator = $this->retrieveLoggedUserProfile->getProfile();
        assert($moderator !== null);

        $back = TimeVerificationController::returnParameters($request);

        try {
            $this->messageBus->dispatch(new ConfirmPuzzlePiecesCount(
                puzzleId: $puzzleId,
                decidedById: $moderator->playerId,
                seenPiecesCount: $request->request->getInt('pieces'),
            ));
        } catch (PuzzleIsStillSecret) {
            $this->addFlash('warning', 'A competition keeps this puzzle secret until its reveal - nothing was saved.');

            return $this->redirectToRoute('admin_time_verification', $back);
        } catch (HandlerFailedException $exception) {
            if ($exception->getPrevious() instanceof SuspiciousTimeCaseChanged) {
                $this->addFlash('warning', 'The puzzle\'s piece count changed meanwhile - nothing was saved.');

                return $this->redirectToRoute('admin_time_verification', $back);
            }

            throw $exception;
        }

        $this->addFlash('success', 'Piece count confirmed - its times are now in the list one by one.');

        return $this->redirectToRoute('admin_time_verification', $back);
    }
}
