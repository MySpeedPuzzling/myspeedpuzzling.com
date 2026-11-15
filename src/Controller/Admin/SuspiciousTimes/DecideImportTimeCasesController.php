<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin\SuspiciousTimes;

use SpeedPuzzling\Web\Message\DecideImportTimeCases;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\SuspicionDirection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

/**
 * An import card of the time verification queue decided as a whole (docs/features/suspicious-time-review.md "Import
 * cards"): "Looks fine - all N" / "Mark all N as needing verification". POST + CSRF; the form names the import's
 * "Add" and the direction - the cases are worked out by the server and each is decided through the single card's
 * own message. Reached by admins and moderators only (the queue's access rule covers the path).
 */
final class DecideImportTimeCasesController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: '/admin/time-verification/imports/{batchId}/{decision}',
        name: 'admin_time_verification_import',
        requirements: ['batchId' => TimeVerificationController::ID_REQUIREMENT, 'decision' => 'trust|mark'],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $batchId, string $decision): Response
    {
        if ($this->isCsrfTokenValid('time-verification-import-' . $batchId, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $moderator = $this->retrieveLoggedUserProfile->getProfile();
        assert($moderator !== null);

        $back = TimeVerificationController::returnParameters($request);
        $note = TimeVerificationController::note($request);
        $direction = SuspicionDirection::tryFrom($request->request->getString('direction'));

        if ($note === false) {
            $this->addFlash('danger', sprintf('The note can have at most %d characters - nothing was saved.', TimeVerificationController::NOTE_MAX_LENGTH));

            return $this->redirectToRoute('admin_time_verification', $back);
        }

        // Only a hand-made request comes without the direction of the card
        if ($direction === null) {
            return $this->redirectToRoute('admin_time_verification', $back);
        }

        $outcome = $this->messageBus->dispatch(new DecideImportTimeCases($batchId, $direction, $moderator->playerId, $decision === 'mark', $note))
            ->last(HandledStamp::class)
            ?->getResult();
        $decided = is_array($outcome) && is_int($outcome['decided'] ?? null) ? $outcome['decided'] : 0;
        $changed = is_array($outcome) && is_int($outcome['changed'] ?? null) ? $outcome['changed'] : 0;
        $more = is_array($outcome) && ($outcome['more'] ?? false) === true;

        $this->addFlash($decided > 0 ? 'success' : 'warning', sprintf(
            '%s: %d %s.%s%s',
            $decision === 'mark' ? 'Marked as needing verification' : 'Verified fine',
            $decided,
            $decided === 1 ? 'result' : 'results',
            $changed > 0 ? sprintf(' %d changed meanwhile and %s left as %s.', $changed, $changed === 1 ? 'was' : 'were', $changed === 1 ? 'it is' : 'they are') : '',
            $more ? ' More of this import are left - the card is still here.' : '',
        ));

        return $this->redirectToRoute('admin_time_verification', $back);
    }
}
