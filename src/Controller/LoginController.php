<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Renders the sign-in page. The POST it submits is handled by
 * LoginFormAuthenticator before routing ever reaches this controller, so this
 * action only ever renders the page.
 *
 * The page must not start a session: AuthenticationUtils only reads the session
 * when the visitor already has one, which keeps anonymous GETs session-free
 * (#164 constraint, README §Anonymous-cacheability).
 */
final class LoginController extends AbstractController
{
    public function __invoke(AuthenticationUtils $authenticationUtils): Response
    {
        // Only a *fully* authenticated visitor is bounced away. A visitor holding
        // nothing but the 30-day remember-me cookie must be able to reach this
        // form, or any access_control/IsGranted rule that still asks for
        // IS_AUTHENTICATED_FULLY turns into an infinite redirect: the rule denies,
        // LoginEntryPoint sends them to /login, and /login sends them back to the
        // page that denied them. Rendering the form instead lets them upgrade the
        // remember-me token to a full one, which is the only way out of that loop.
        if ($this->isGranted('IS_AUTHENTICATED_FULLY')) {
            return $this->redirectToRoute('my_profile');
        }

        return $this->render('login.html.twig', [
            'last_email' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }
}
