<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\EventSubscriber\NativeAuthPageSubscriber;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The target of every emailed sign-in link. The login_link authenticator claims
 * this path on the main firewall and answers before routing hands over, so this
 * action only runs if the firewall is not in play at all (misconfiguration) -
 * in which case the safe move is to send the visitor back for a fresh link
 * rather than to render a page that suggests they are signed in.
 */
final class SignInLinkCheckController extends AbstractController
{
    #[Route(
        path: '/login-link/check',
        name: 'sign_in_link_check',
        defaults: [NativeAuthPageSubscriber::ROUTE_DEFAULT => true],
        methods: ['GET'],
    )]
    public function __invoke(): Response
    {
        return $this->redirectToRoute('sign_in_link_request');
    }
}
