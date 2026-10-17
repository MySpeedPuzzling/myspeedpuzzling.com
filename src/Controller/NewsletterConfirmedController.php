<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\InvalidNewsletterToken;
use SpeedPuzzling\Web\Exceptions\NewsletterConfirmTokenExpired;
use SpeedPuzzling\Web\Services\NewsletterTokenSigner;
use SpeedPuzzling\Web\Value\NewsletterAudience;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "You're in!" after the confirmation button (NewsletterConfirmSubmitController redirects here). Shows only - the
 * token tells whether to pitch an account (guest) or not (player).
 */
final class NewsletterConfirmedController extends AbstractController
{
    public function __construct(
        private readonly NewsletterTokenSigner $tokenSigner,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/newsletter/potvrzeni/{token}/hotovo',
            'en' => '/en/newsletter/confirm/{token}/done',
            'es' => '/es/newsletter/confirmar/{token}/listo',
            'ja' => '/ja/newsletter/confirm/{token}/done',
            'fr' => '/fr/newsletter/confirmation/{token}/termine',
            'de' => '/de/newsletter/bestaetigung/{token}/fertig',
        ],
        name: 'newsletter_confirmed',
        methods: ['GET'],
    )]
    public function __invoke(string $token): Response
    {
        try {
            $isPlayer = $this->tokenSigner->parseConfirmToken($token)->audience === NewsletterAudience::Player;
        } catch (NewsletterConfirmTokenExpired) {
            // Confirmed just before the link ran out - the page is still the right answer
            $isPlayer = false;
        } catch (InvalidNewsletterToken) {
            return $this->render('newsletter/invalid-token.html.twig', [
                'messageKey' => 'newsletter.invalid.invalid_text',
            ], new Response(status: Response::HTTP_NOT_FOUND));
        }

        return $this->render('newsletter/confirmed.html.twig', [
            'isPlayer' => $isPlayer,
        ]);
    }
}
