<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Controller\EventResultClaim\AbstractEventResultClaimController;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\AllowEventResultClaimsAgain;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Allow claiming again" on the admin page of claim blocks (docs/features/event-results-import/README.md "Gates").
 */
final class AllowEventClaimsAgainController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: '/admin/event-claim-blocks/{playerId}/allow',
        name: 'admin_event_claim_allow',
        requirements: ['playerId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    #[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
    public function __invoke(string $playerId, Request $request): Response
    {
        if ($this->isCsrfTokenValid(AbstractEventResultClaimController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            $this->addFlash('danger', 'The page was open too long - nothing was changed. Try again.');
        } else {
            $this->messageBus->dispatch(new AllowEventResultClaimsAgain($playerId));
            $this->addFlash('success', 'The player can claim event results again.');
        }

        return $this->redirectToRoute('admin_event_claim_blocks', status: Response::HTTP_SEE_OTHER);
    }
}
