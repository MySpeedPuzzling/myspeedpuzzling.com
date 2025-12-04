<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Test;

use Auth0\Symfony\Models\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Test-only controller for logging in users during Panther E2E tests.
 *
 * This controller is only registered in dev/test environments via config/packages/dev/services.php
 * and config/packages/test/services.php. It bypasses Auth0 authentication.
 */
#[Route(path: '/_test/login', name: 'test_login')]
final class TestLoginController extends AbstractController
{
    public function __construct(
        readonly private Security $security,
    ) {
    }

    public function __invoke(string $userId, string $email, string $name): Response
    {
        $auth0User = new User([
            'user_id' => $userId,
            'sub' => $userId,
            'email' => $email,
            'name' => $name,
            'email_verified' => true,
        ]);

        $this->security->login($auth0User, 'auth0.authenticator', 'main');

        return new Response('Logged in as ' . $name);
    }
}
