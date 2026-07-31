<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Controllers\AuthenticationController;
use SpeedPuzzling\Web\Message\RecordAuthAuditEvent;
use SpeedPuzzling\Web\Services\AuthAuditRecorder;
use SpeedPuzzling\Web\Value\AuthAuditEventType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Transition-window escape hatch (Stage B -> Phase 6 of issue #147): the native
 * login page carries a subdued link here, and this action starts the exact same
 * hosted Universal Login redirect that /login performed before the flip. The
 * callback route and the Auth0 authenticator are still wired, so the resulting
 * session is a legacy Auth0 session - valid until Phase 6 removes the stack.
 *
 * Exists for the one gap native login cannot cover: a password changed through
 * Auth0 after the hash export was cut (stale local hash - the new password
 * fails natively but still works here). Flag-gated; dies in Phase 6.
 */
final class Auth0FallbackLoginController extends AbstractController
{
    public function __construct(
        private readonly AuthenticationController $auth0AuthenticationController,
        private readonly AuthAuditRecorder $authAuditRecorder,
        private readonly bool $auth0FallbackLoginEnabled,
    ) {
    }

    #[Route(path: '/login/auth0', name: 'auth0_fallback_login', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        if ($this->auth0FallbackLoginEnabled === false) {
            throw new NotFoundHttpException();
        }

        if ($this->getUser() !== null) {
            return $this->redirectToRoute('my_profile');
        }

        // Who comes back through the Auth0 door is not known yet (the redirect
        // starts here, identity arrives at the callback) - the row still counts
        // fallback usage, which is the Phase 6 exit metric
        $this->authAuditRecorder->record(new RecordAuthAuditEvent(
            eventType: AuthAuditEventType::Auth0FallbackLogin,
            authenticator: 'auth0_fallback',
            ipAddress: $request->getClientIp(),
            userAgent: $request->headers->get('User-Agent'),
        ));

        return $this->auth0AuthenticationController->login($request);
    }
}
