<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Repository\OAuth2ClientRequestRepository;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class OAuth2ClientRequestDetailController extends AbstractController
{
    public function __construct(
        private readonly OAuth2ClientRequestRepository $requestRepository,
    ) {
    }

    #[Route(
        path: '/admin/oauth2-requests/{requestId}',
        name: 'admin_oauth2_client_request_detail',
    )]
    #[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
    public function __invoke(#[CurrentUser] User $user, string $requestId): Response
    {
        $request = $this->requestRepository->get($requestId);

        return $this->render('admin/oauth2_client_request_detail.html.twig', [
            'request' => $request,
        ]);
    }
}
