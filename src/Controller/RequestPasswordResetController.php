<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\EventSubscriber\NativeAuthPageSubscriber;
use SpeedPuzzling\Web\Message\RequestPasswordReset;
use SpeedPuzzling\Web\Message\SendPasswordResetLink;
use SpeedPuzzling\Web\Value\PasswordResetToken;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Forgot password?" (issue #147). Answers identically whether or not the
 * address has an account, and whether or not a live request already throttles a
 * second mail - the page must never become a way to probe who is registered
 * (D8 enumeration tradeoff, mirrors the sign-in link endpoint).
 */
final class RequestPasswordResetController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'request_password_reset';

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly RateLimiterFactoryInterface $passwordResetEmailLimiter,
        private readonly RateLimiterFactoryInterface $passwordResetIpLimiter,
        private readonly bool $nativeRegistrationEnabled,
        private readonly bool $nativeLoginEnabled,
    ) {
    }

    #[Route(
        path: '/password-reset',
        name: 'request_password_reset',
        defaults: [NativeAuthPageSubscriber::ROUTE_DEFAULT => true],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request): Response
    {
        // Reachable only once a native account can exist (Stage A onwards). Before
        // that the user_account table is empty, so this page could only ever promise
        // a mail it will not send - and its answer is uniform by design, so the dead
        // end would be silent. Both flags, not just registration: a rollback that
        // leaves login native must not take password reset down with it.
        if ($this->nativeRegistrationEnabled === false && $this->nativeLoginEnabled === false) {
            return $this->redirectToRoute('login');
        }

        if ($request->isMethod('POST') === false) {
            return $this->render('request_password_reset.html.twig', [
                'email' => $request->query->getString('email'),
            ]);
        }

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $email = trim((string) $request->request->get('email'));

        if ($email === '') {
            $this->addFlash('warning', $this->translator->trans('auth.password_reset.email_required'));

            return $this->redirectToRoute('request_password_reset');
        }

        if ($this->consumeRateLimit($email, $request->getClientIp()) === false) {
            $this->addFlash('warning', $this->translator->trans('auth.password_reset.too_many_requests'));

            return $this->redirectToRoute('request_password_reset');
        }

        try {
            $envelope = $this->messageBus->dispatch(
                new RequestPasswordReset(email: $email),
            );

            /** @var HandledStamp $handledStamp */
            $handledStamp = $envelope->last(HandledStamp::class);
            $token = $handledStamp->getResult();
            assert($token === null || $token instanceof PasswordResetToken);

            // null means unknown address or an already-live request - both silent
            if ($token !== null) {
                $this->messageBus->dispatch(
                    new SendPasswordResetLink(
                        email: $email,
                        token: $token->toString(),
                        fallbackLocale: $request->getLocale(),
                    ),
                );
            }
        } catch (HandlerFailedException $exception) {
            $this->logger->error('Could not issue a password reset link', [
                'exception' => $exception,
            ]);

            $this->addFlash('danger', $this->translator->trans('auth.password_reset.failed'));

            return $this->redirectToRoute('request_password_reset');
        }

        $this->addFlash('success', $this->translator->trans('auth.password_reset.sent'));

        return $this->redirectToRoute('request_password_reset');
    }

    private function consumeRateLimit(string $email, null|string $clientIp): bool
    {
        $perEmail = $this->passwordResetEmailLimiter
            ->create(UserAccount::canonicalizeEmail($email))
            ->consume();

        $perIp = $this->passwordResetIpLimiter
            ->create($clientIp ?? 'unknown')
            ->consume();

        return $perEmail->isAccepted() && $perIp->isAccepted();
    }
}
