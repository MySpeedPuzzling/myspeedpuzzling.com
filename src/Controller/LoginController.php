<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Controllers\AuthenticationController;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * The /login route through the Auth0 migration (issue #147). The URL never
 * changes - only what answers on it:
 *
 * - `native_login` OFF (today, and window A): hands over to the Auth0 bundle
 *   controller, which redirects to the hosted Universal Login exactly as before.
 * - `native_login` ON (Stage B): renders our own form. The POST it submits is
 *   handled by LoginFormAuthenticator before routing ever reaches this
 *   controller, so this action only ever renders the page.
 *
 * The page must not start a session: AuthenticationUtils only reads the session
 * when the visitor already has one, which keeps anonymous GETs session-free
 * (#164 constraint, README §Anonymous-cacheability).
 */
final class LoginController extends AbstractController
{
    public function __construct(
        private readonly AuthenticationController $auth0AuthenticationController,
        private readonly bool $nativeLoginEnabled,
    ) {
    }

    public function __invoke(Request $request, AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->nativeLoginEnabled === false) {
            return $this->auth0AuthenticationController->login($request);
        }

        if ($this->getUser() !== null) {
            return $this->redirectToRoute('my_profile');
        }

        return $this->render('login.html.twig', [
            'last_email' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }
}
