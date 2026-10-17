<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\InvalidNewsletterToken;
use SpeedPuzzling\Web\Exceptions\NewsletterConfirmTokenExpired;
use SpeedPuzzling\Web\Services\NewsletterTokenSigner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Double opt-in confirmation link from the newsletter confirmation e-mail: only *shows* the address and a
 * "Yes, subscribe me" button (NewsletterConfirmSubmitController). Mail security scanners open every link of an
 * incoming e-mail - confirming on GET subscribed addresses that bots had typed into the footer form, nobody asked.
 */
final class NewsletterConfirmController extends AbstractController
{
    public function __construct(
        private readonly NewsletterTokenSigner $tokenSigner,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/newsletter/potvrzeni/{token}',
            'en' => '/en/newsletter/confirm/{token}',
            'es' => '/es/newsletter/confirmar/{token}',
            'ja' => '/ja/newsletter/confirm/{token}',
            'fr' => '/fr/newsletter/confirmation/{token}',
            'de' => '/de/newsletter/bestaetigung/{token}',
        ],
        name: 'newsletter_confirm',
        methods: ['GET'],
    )]
    public function __invoke(string $token): Response
    {
        try {
            $claim = $this->tokenSigner->parseConfirmToken($token);
        } catch (NewsletterConfirmTokenExpired) {
            return $this->renderInvalid('newsletter.invalid.expired_text');
        } catch (InvalidNewsletterToken) {
            return $this->renderInvalid('newsletter.invalid.invalid_text');
        }

        return $this->render('newsletter/confirm.html.twig', [
            'token' => $token,
            'email' => $claim->email,
        ]);
    }

    private function renderInvalid(string $messageKey): Response
    {
        return $this->render('newsletter/invalid-token.html.twig', [
            'messageKey' => $messageKey,
        ], new Response(status: Response::HTTP_NOT_FOUND));
    }
}
