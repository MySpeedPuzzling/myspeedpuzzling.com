<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\EventSubscriber\NativeAuthPageSubscriber;
use SpeedPuzzling\Web\Services\CheckEmailFlash;
use SpeedPuzzling\Web\Value\ReturnUrl;
use SpeedPuzzling\Web\Value\WebmailProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Check your email" after asking for a sign-in link (auth UX redesign §4.4):
 * says where the link went, lets the visitor fix a typo in the address, opens
 * their webmail and offers a fresh link.
 *
 * The address arrives in a flash from SignInLinkController (CheckEmailFlash).
 * No flash (a reload, a bookmark, a direct visit) means there is nothing to
 * show - back to the request form. Identical for addresses with and without an
 * account (D8).
 */
final class SignInLinkSentController extends AbstractController
{
    public function __construct(
        private readonly int $signInLinkLifetimeSeconds,
    ) {
    }

    #[Route(
        path: '/login-link/sent',
        name: 'sign_in_link_sent',
        defaults: [NativeAuthPageSubscriber::ROUTE_DEFAULT => true],
        methods: ['GET'],
    )]
    public function __invoke(Request $request): Response
    {
        $returnUrl = ReturnUrl::tryFrom($request->query->getString('return'));
        $sent = CheckEmailFlash::take($request, CheckEmailFlash::SIGN_IN_LINK);

        if ($sent === null) {
            return $this->redirectToRoute(
                'sign_in_link_request',
                $returnUrl === null ? [] : ['return' => $returnUrl->path],
                Response::HTTP_SEE_OTHER,
            );
        }

        return $this->render('sign_in_link_sent.html.twig', [
            'email' => $sent['email'],
            'resent' => $sent['resent'],
            'webmail' => WebmailProvider::fromEmail($sent['email']),
            'expires_in_minutes' => intdiv($this->signInLinkLifetimeSeconds, 60),
            'return_path' => $returnUrl?->path,
        ]);
    }
}
