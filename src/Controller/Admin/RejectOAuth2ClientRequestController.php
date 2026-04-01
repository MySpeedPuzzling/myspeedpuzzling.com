<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Message\RejectOAuth2ClientRequest;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class RejectOAuth2ClientRequestController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: '/admin/oauth2-requests/{requestId}/reject',
        name: 'admin_reject_oauth2_client_request',
        methods: ['POST'],
    )]
    #[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
    public function __invoke(#[CurrentUser] User $user, Request $request, string $requestId): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();
        assert($profile !== null);

        $reason = trim((string) $request->request->get('reason', ''));

        if ($reason === '') {
            $this->addFlash('danger', 'Please provide a rejection reason.');

            return $this->redirectToRoute('admin_oauth2_client_request_detail', ['requestId' => $requestId]);
        }

        $this->messageBus->dispatch(
            new RejectOAuth2ClientRequest(
                requestId: $requestId,
                adminPlayerId: $profile->playerId,
                reason: $reason,
            ),
        );

        $this->addFlash('success', 'OAuth2 client request rejected. User has been notified.');

        return $this->redirectToRoute('admin_oauth2_client_requests');
    }
}
