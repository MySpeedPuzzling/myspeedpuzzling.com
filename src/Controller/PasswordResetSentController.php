<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Entity\ResetPasswordRequest;
use SpeedPuzzling\Web\EventSubscriber\NativeAuthPageSubscriber;
use SpeedPuzzling\Web\Services\CheckEmailFlash;
use SpeedPuzzling\Web\Value\WebmailProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Check your email" after "Forgot password?" (auth UX redesign §4.5) - the
 * same screen as after asking for a sign-in link, minus anything that signs in.
 * The address arrives in a flash (CheckEmailFlash); without one, back to the
 * request form. Identical for addresses with and without an account (D8).
 */
final class PasswordResetSentController extends AbstractController
{
    #[Route(
        path: '/password-reset/sent',
        name: 'password_reset_sent',
        defaults: [NativeAuthPageSubscriber::ROUTE_DEFAULT => true],
        methods: ['GET'],
        // "sent" also fits /password-reset/{token}'s deliberately loose token
        // requirement, so this route must be matched first
        priority: 10,
    )]
    public function __invoke(Request $request): Response
    {
        $sent = CheckEmailFlash::take($request, CheckEmailFlash::PASSWORD_RESET);

        if ($sent === null) {
            return $this->redirectToRoute('request_password_reset', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('password_reset_sent.html.twig', [
            'email' => $sent['email'],
            'resent' => $sent['resent'],
            'webmail' => WebmailProvider::fromEmail($sent['email']),
            'expires_in_minutes' => ResetPasswordRequest::LIFETIME_MINUTES,
        ]);
    }
}
