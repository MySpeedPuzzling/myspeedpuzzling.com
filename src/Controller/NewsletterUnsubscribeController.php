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
 * Landing page of the unsubscribe link in every newsletter. Shows the two
 * options (one-click unsubscribe / manage notification settings) without
 * changing anything - the actual unsubscribe is a POST, so scanners
 * prefetching links in e-mails cannot unsubscribe anyone by accident.
 */
final class NewsletterUnsubscribeController extends AbstractController
{
    public function __construct(
        private readonly NewsletterTokenSigner $tokenSigner,
        private readonly EmailPreferencesLinkGenerator $emailPreferencesLinkGenerator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/newsletter/odhlasit/{token}',
            'en' => '/en/newsletter/unsubscribe/{token}',
            'es' => '/es/newsletter/cancelar/{token}',
            'ja' => '/ja/newsletter/unsubscribe/{token}',
            'fr' => '/fr/newsletter/desabonnement/{token}',
            'de' => '/de/newsletter/abmelden/{token}',
        ],
        name: 'newsletter_unsubscribe',
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

        return $this->render('newsletter/unsubscribe.html.twig', [
            'token' => $token,
            'email' => $claim->email,
            'isPlayer' => $isPlayer,
            'preferencesUrl' => $isPlayer
                ? $this->emailPreferencesLinkGenerator->forPlayer($claim->id, $claim->email, $request->getLocale())
                : null,
        ]);
    }
}
