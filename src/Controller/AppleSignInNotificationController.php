<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\InvalidAppleServerNotification;
use SpeedPuzzling\Web\Message\ProcessAppleSignInEvent;
use SpeedPuzzling\Web\Services\SocialLogin\AppleServerNotificationVerifier;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sign in with Apple server-to-server notifications (registered on the App ID
 * in the Apple Developer portal, docs/features/auth-hardening/setup-apple.md).
 * Server-to-server: no session, no cookies, no CSRF (the `stateless` firewall
 * covers the path) - the token's Apple signature is the authentication.
 *
 * Deliberately independent of SOCIAL_LOGIN_APPLE_ENABLED: an Apple ID deleted
 * while the flag is off must still lose its identity.
 */
final class AppleSignInNotificationController extends AbstractController
{
    public function __construct(
        private readonly AppleServerNotificationVerifier $verifier,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/webhook/apple-sign-in', name: 'apple_sign_in_notification', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        try {
            $event = $this->verifier->verify($request->getContent());
        } catch (InvalidAppleServerNotification $exception) {
            // Anyone can POST here - a rejected token is routine, not an incident
            $this->logger->info('Rejected Apple sign-in notification.', [
                'exception' => $exception,
            ]);

            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        // Genuine but of a kind we do not know: acknowledge, so Apple does not retry it
        if ($event->type === null) {
            $this->logger->info('Ignored Apple sign-in notification of an unknown type.', [
                'event' => $event->rawType,
            ]);

            return new Response('', Response::HTTP_OK);
        }

        $this->messageBus->dispatch(new ProcessAppleSignInEvent($event->type, $event->providerUserId));

        return new Response('', Response::HTTP_OK);
    }
}
