<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\EventSubscriber\NativeAuthPageSubscriber;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The target of every emailed sign-in link.
 *
 * Opening the link (GET) does not sign anybody in: it renders a form that
 * re-posts the link's `user`, `expires` and `hash` to this same path, and only
 * that POST is claimed by the login_link authenticator (`check_post_only`,
 * config/packages/security.php). The page submits itself with an inline
 * script, so a person still lands straight on their profile; a "Sign me in"
 * button covers browsers without JavaScript. Mail providers fetch the URLs they
 * deliver - Outlook's Safe Links does it at the moment of the click, from
 * Microsoft's own servers, with a bare HTTP client - and a GET that signed in
 * consumed the single-use link before the reader's browser ever arrived. A
 * fetch that only renders a form burns nothing.
 *
 * The authenticator answers the POST before routing hands over, so a POST only
 * reaches this action when the firewall is not in play at all (misconfiguration);
 * the safe move is then to send the visitor back for a fresh link rather than
 * to render a page that suggests they are signed in.
 */
final class SignInLinkCheckController extends AbstractController
{
    #[Route(
        path: '/login-link/check',
        name: 'sign_in_link_check',
        defaults: [NativeAuthPageSubscriber::ROUTE_DEFAULT => true],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request): Response
    {
        if (!$request->isMethodSafe()) {
            return $this->redirectToRoute('sign_in_link_request');
        }

        $user = $request->query->get('user');
        $expires = $request->query->get('expires');
        $hash = $request->query->get('hash');

        // Not signature-checked here on purpose: a tampered link fails in the
        // authenticator on submit, with the same message as an expired or used one
        if (!is_string($user) || $user === '' || !is_string($expires) || $expires === '' || !is_string($hash) || $hash === '') {
            return $this->redirectToRoute('sign_in_link_request');
        }

        return $this->render('sign_in_link_check.html.twig', [
            'user' => $user,
            'expires' => $expires,
            'hash' => $hash,
        ]);
    }
}
