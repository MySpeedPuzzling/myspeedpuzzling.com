<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\InvalidNewsletterToken;
use SpeedPuzzling\Web\Exceptions\NewsletterConfirmTokenExpired;
use SpeedPuzzling\Web\Message\ConfirmNewsletterSubscription;
use SpeedPuzzling\Web\Services\NewsletterTokenSigner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The "Yes, subscribe me" button of the confirmation page (NewsletterConfirmController) - the double opt-in happens
 * here, never on the e-mail link itself.
 */
final class NewsletterConfirmSubmitController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'newsletter-confirm';

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly NewsletterTokenSigner $tokenSigner,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/newsletter/potvrzeni/{token}/potvrdit',
            'en' => '/en/newsletter/confirm/{token}/confirm',
            'es' => '/es/newsletter/confirmar/{token}/confirmar',
            'ja' => '/ja/newsletter/confirm/{token}/confirm',
            'fr' => '/fr/newsletter/confirmation/{token}/confirmer',
            'de' => '/de/newsletter/bestaetigung/{token}/bestaetigen',
        ],
        name: 'newsletter_confirm_submit',
        methods: ['POST'],
    )]
    public function __invoke(string $token, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('newsletter_confirm', ['token' => $token]);
        }

        try {
            $this->tokenSigner->parseConfirmToken($token);
            $this->messageBus->dispatch(new ConfirmNewsletterSubscription($token));
        } catch (NewsletterConfirmTokenExpired) {
            return $this->renderInvalid('newsletter.invalid.expired_text');
        } catch (InvalidNewsletterToken) {
            return $this->renderInvalid('newsletter.invalid.invalid_text');
        } catch (HandlerFailedException $exception) {
            $previous = $exception->getPrevious();

            if ($previous instanceof NewsletterConfirmTokenExpired) {
                return $this->renderInvalid('newsletter.invalid.expired_text');
            }

            if ($previous instanceof InvalidNewsletterToken) {
                return $this->renderInvalid('newsletter.invalid.invalid_text');
            }

            throw $exception;
        }

        // Never render the result here: Turbo Drive discards a 200 answer to a form submission
        return $this->redirectToRoute('newsletter_confirmed', ['token' => $token], Response::HTTP_SEE_OTHER);
    }

    private function renderInvalid(string $messageKey): Response
    {
        return $this->render('newsletter/invalid-token.html.twig', [
            'messageKey' => $messageKey,
        ], new Response(status: Response::HTTP_NOT_FOUND));
    }
}
