<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\EventSubscriber\NativeAuthPageSubscriber;
use SpeedPuzzling\Web\Message\RequestSignInLink;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Email me a sign-in link" (D6, issue #147). Live from Stage A: it is the
 * rescue both for users whose password manager filed the credential under the
 * Auth0 domain and for window-A native registrants who log out while /login
 * still points at Auth0.
 *
 * The answer is the same whether or not the address has an account - the page
 * never reveals who is registered (D8 enumeration tradeoff).
 */
final class SignInLinkController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'sign_in_link';

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly RateLimiterFactoryInterface $signInLinkEmailLimiter,
        private readonly RateLimiterFactoryInterface $signInLinkIpLimiter,
        private readonly int $signInLinkLifetimeSeconds,
    ) {
    }

    #[Route(
        path: '/login-link',
        name: 'sign_in_link_request',
        defaults: [NativeAuthPageSubscriber::ROUTE_DEFAULT => true],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('POST') === false) {
            return $this->render('sign_in_link.html.twig', [
                'email' => $request->query->getString('email'),
                'expires_in_minutes' => intdiv($this->signInLinkLifetimeSeconds, 60),
            ]);
        }

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $email = trim((string) $request->request->get('email'));

        if ($email === '') {
            $this->addFlash('warning', $this->translator->trans('auth.sign_in_link.email_required'));

            return $this->redirectToRoute('sign_in_link_request');
        }

        if ($this->consumeRateLimit($email, $request->getClientIp()) === false) {
            $this->addFlash('warning', $this->translator->trans('auth.sign_in_link.too_many_requests'));

            return $this->redirectToRoute('sign_in_link_request');
        }

        try {
            $this->messageBus->dispatch(
                new RequestSignInLink(
                    email: $email,
                    fallbackLocale: $request->getLocale(),
                ),
            );
        } catch (HandlerFailedException $exception) {
            $this->logger->error('Could not issue a sign-in link', [
                'exception' => $exception,
            ]);

            $this->addFlash('danger', $this->translator->trans('auth.sign_in_link.failed'));

            return $this->redirectToRoute('sign_in_link_request');
        }

        // Deliberately identical for known and unknown addresses
        $this->addFlash('success', $this->translator->trans('auth.sign_in_link.sent'));

        return $this->redirectToRoute('sign_in_link_request');
    }

    /**
     * Unauthenticated endpoint that sends mail to an address the caller picks:
     * throttled per address (mail cannon aimed at one inbox) and per client IP
     * (many addresses from one place).
     */
    private function consumeRateLimit(string $email, null|string $clientIp): bool
    {
        $perEmail = $this->signInLinkEmailLimiter
            ->create(UserAccount::canonicalizeEmail($email))
            ->consume();

        $perIp = $this->signInLinkIpLimiter
            ->create($clientIp ?? 'unknown')
            ->consume();

        return $perEmail->isAccepted() && $perIp->isAccepted();
    }
}
