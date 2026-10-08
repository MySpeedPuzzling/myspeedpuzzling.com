<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin\SuspiciousTimes;

use SpeedPuzzling\Web\Exceptions\PuzzleIsStillSecret;
use SpeedPuzzling\Web\Exceptions\SuspiciousTimeCaseChanged;
use SpeedPuzzling\Web\Message\SetPuzzleSlowThreshold;
use SpeedPuzzling\Web\Security\SuspiciousResultsVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeScan;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "A hard puzzle" on a puzzle card (docs/features/suspicious-time-review.md): the slow threshold is saved, then the
 * puzzle's times are judged again at once - the cases within the new bar close. `remove` takes the threshold away.
 */
#[IsGranted(SuspiciousResultsVoter::REVIEW_SUSPICIOUS_TIMES)]
final class SetPuzzleSlowThresholdController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly SuspiciousTimeScan $suspiciousTimeScan,
    ) {
    }

    #[Route(
        path: '/admin/time-verification/puzzles/{puzzleId}/slow-threshold',
        name: 'admin_time_verification_slow_threshold',
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
        $remove = $request->request->has('remove');
        $slowThreshold = null;

        if ($remove === false) {
            $typed = str_replace(',', '.', trim($request->request->getString('slow_threshold')));

            if (is_numeric($typed) === false || SetPuzzleSlowThreshold::isValid((float) $typed) === false) {
                $this->addFlash('warning', sprintf('The threshold is a number from %d to %d - nothing was saved.', SetPuzzleSlowThreshold::MIN, SetPuzzleSlowThreshold::MAX));

                return $this->redirectToRoute('admin_time_verification', $back);
            }

            $slowThreshold = round((float) $typed, 1);
        }

        try {
            $this->messageBus->dispatch(new SetPuzzleSlowThreshold(
                puzzleId: $puzzleId,
                decidedById: $moderator->playerId,
                seenPiecesCount: $request->request->getInt('pieces'),
                slowThreshold: $slowThreshold,
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

        $summary = $this->suspiciousTimeScan->forPuzzle($puzzleId);
        $saved = $slowThreshold === null
            ? 'The usual slow thresholds apply to this puzzle again.'
            : sprintf('Saved: times on this puzzle are too slow only from %s× the expected time.', rtrim(rtrim(number_format($slowThreshold, 1, '.', ''), '0'), '.'));

        if ($summary === null) {
            $this->addFlash('success', $saved . ' The next scan judges its times again.');
        } else {
            $this->addFlash('success', sprintf(
                '%s Its times were judged again: %d %s closed, %d still %s a look.',
                $saved,
                $summary->goneCases,
                $summary->goneCases === 1 ? 'case' : 'cases',
                $summary->raised,
                $summary->raised === 1 ? 'needs' : 'need',
            ));
        }

        return $this->redirectToRoute('admin_time_verification', $back);
    }
}
