<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\InvalidNewsletterToken;
use SpeedPuzzling\Web\Message\UnsubscribeFromNewsletter;
use SpeedPuzzling\Web\Services\EmailPreferencesLinkGenerator;
use SpeedPuzzling\Web\Services\NewsletterTokenSigner;
use SpeedPuzzling\Web\Value\NewsletterAudience;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class NewsletterUnsubscribeConfirmController extends AbstractController
{
    private const string CSRF_TOKEN_ID = 'newsletter-unsubscribe';

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly NewsletterTokenSigner $tokenSigner,
        private readonly EmailPreferencesLinkGenerator $emailPreferencesLinkGenerator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/newsletter/odhlasit/{token}/potvrdit',
            'en' => '/en/newsletter/unsubscribe/{token}/confirm',
            'es' => '/es/newsletter/cancelar/{token}/confirmar',
            'ja' => '/ja/newsletter/unsubscribe/{token}/confirm',
            'fr' => '/fr/newsletter/desabonnement/{token}/confirmer',
            'de' => '/de/newsletter/abmelden/{token}/bestaetigen',
        ],
        name: 'newsletter_unsubscribe_confirm',
        methods: ['POST'],
    )]
    public function __invoke(string $token, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('newsletter_unsubscribe', ['token' => $token]);
        }

        try {
            $claim = $this->tokenSigner->parseUnsubscribeToken($token);
            $this->messageBus->dispatch(new UnsubscribeFromNewsletter($token));
        } catch (InvalidNewsletterToken) {
            return $this->renderInvalid();
        } catch (HandlerFailedException $exception) {
            if ($exception->getPrevious() instanceof InvalidNewsletterToken) {
                return $this->renderInvalid();
            }

            throw $exception;
        }

        $isPlayer = $claim->audience === NewsletterAudience::Player;

        return $this->render('newsletter/unsubscribed.html.twig', [
            'isPlayer' => $isPlayer,
            'preferencesUrl' => $isPlayer
                ? $this->emailPreferencesLinkGenerator->forPlayer($claim->id, $claim->email, $request->getLocale())
                : null,
        ]);
    }

    private function renderInvalid(): Response
    {
        return $this->render('newsletter/invalid-token.html.twig', [
            'messageKey' => 'newsletter.invalid.invalid_text',
        ], new Response(status: Response::HTTP_NOT_FOUND));
    }
}
