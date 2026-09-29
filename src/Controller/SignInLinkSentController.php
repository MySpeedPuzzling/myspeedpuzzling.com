<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\EventSubscriber\NativeAuthPageSubscriber;
use SpeedPuzzling\Web\Services\CheckEmailFlash;
use SpeedPuzzling\Web\Services\SignInCodePending;
use SpeedPuzzling\Web\Value\ReturnUrl;
use SpeedPuzzling\Web\Value\WebmailProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Check your email" after asking for a sign-in code (auth UX redesign §4.4):
 * the 6-digit code input (phase 2), where the mail went, a fix for a typo in
 * the address, a shortcut into the webmail and a fresh code.
 *
 * Shown while this browser has a sign-in pending (SignInCodePending) - also
 * after a reload, which matters in an in-app browser: switching to the mail app
 * to read the code may make it reload the page. The first view after the
 * request reads the CheckEmailFlash as well (the "we sent a new one" line).
 * Nothing pending (never asked, used, expired) - back to the request form.
 * Identical for addresses with and without an account (D8).
 */
final class SignInLinkSentController extends AbstractController
{
    public function __construct(
        private readonly int $signInLinkLifetimeSeconds,
        private readonly SignInCodePending $signInCodePending,
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
        $pending = $this->signInCodePending->get($request);

        $email = $pending['email'] ?? $sent['email'] ?? null;

        if ($email === null) {
            return $this->redirectToRoute(
                'sign_in_link_request',
                $returnUrl === null ? [] : ['return' => $returnUrl->path],
                Response::HTTP_SEE_OTHER,
            );
        }

        return $this->render('sign_in_link_sent.html.twig', self::screen(
            $email,
            $sent !== null && $sent['resent'],
            $returnUrl,
            $this->signInLinkLifetimeSeconds,
            codeAvailable: $pending !== null,
        ));
    }

    /**
     * Template parameters of the screen, shared with SignInCodeController's
     * 422 re-render so the two can never drift.
     *
     * @return array<string, mixed>
     */
    public static function screen(
        string $email,
        bool $resent,
        null|ReturnUrl $returnUrl,
        int $lifetimeSeconds,
        bool $codeAvailable,
        null|string $codeError = null,
        null|string $alert = null,
    ): array {
        return [
            'email' => $email,
            'resent' => $resent,
            'webmail' => WebmailProvider::fromEmail($email),
            'expires_in_minutes' => intdiv($lifetimeSeconds, 60),
            'return_path' => $returnUrl?->path,
            'code_available' => $codeAvailable,
            'code_error' => $codeError,
            'alert' => $alert,
        ];
    }
}
