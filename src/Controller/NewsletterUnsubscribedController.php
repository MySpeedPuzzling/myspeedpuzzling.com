<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\InvalidNewsletterToken;
use SpeedPuzzling\Web\Services\EmailPreferencesLinkGenerator;
use SpeedPuzzling\Web\Services\NewsletterTokenSigner;
use SpeedPuzzling\Web\Value\NewsletterAudience;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Where NewsletterUnsubscribeConfirmController redirects after the unsubscribe. The POST cannot render this
 * page itself: Turbo Drive discards a 200 answer to a form submission, so the visitor clicked the button,
 * got unsubscribed and saw nothing happen.
 */
final class NewsletterUnsubscribedController extends AbstractController
{
    public function __construct(
        private readonly NewsletterTokenSigner $tokenSigner,
        private readonly EmailPreferencesLinkGenerator $emailPreferencesLinkGenerator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/newsletter/odhlasit/{token}/hotovo',
            'en' => '/en/newsletter/unsubscribe/{token}/done',
            'es' => '/es/newsletter/cancelar/{token}/hecho',
            'ja' => '/ja/newsletter/unsubscribe/{token}/done',
            'fr' => '/fr/newsletter/desabonnement/{token}/termine',
            'de' => '/de/newsletter/abmelden/{token}/erledigt',
        ],
        name: 'newsletter_unsubscribed',
        methods: ['GET'],
    )]
    public function __invoke(string $token, Request $request): Response
    {
        try {
            $claim = $this->tokenSigner->parseUnsubscribeToken($token);
        } catch (InvalidNewsletterToken) {
            return $this->render('newsletter/invalid-token.html.twig', [
                'messageKey' => 'newsletter.invalid.invalid_text',
            ], new Response(status: Response::HTTP_NOT_FOUND));
        }

        $isPlayer = $claim->audience === NewsletterAudience::Player;

        $response = $this->render('newsletter/unsubscribed.html.twig', [
            'isPlayer' => $isPlayer,
            'preferencesUrl' => $isPlayer
                ? $this->emailPreferencesLinkGenerator->forPlayer($claim->id, $claim->email, $request->getLocale())
                : null,
        ]);

        // Links to the e-mail preferences capability URL: a shared cache must never store it
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
