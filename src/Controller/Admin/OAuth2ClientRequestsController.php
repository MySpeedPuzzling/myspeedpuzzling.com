<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Query\GetOAuth2ClientRequests;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class OAuth2ClientRequestsController extends AbstractController
{
    public function __construct(
        private readonly GetOAuth2ClientRequests $getOAuth2ClientRequests,
    ) {
    }

    #[Route(
        path: '/admin/oauth2-requests',
        name: 'admin_oauth2_client_requests',
    )]
    #[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
    public function __invoke(#[CurrentUser] User $user): Response
    {
        $requests = $this->getOAuth2ClientRequests->all();

        return $this->render('admin/oauth2_client_requests.html.twig', [
            'requests' => $requests,
        ]);
    }
}
