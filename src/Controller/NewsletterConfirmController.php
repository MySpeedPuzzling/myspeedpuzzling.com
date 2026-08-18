<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\InvalidNewsletterToken;
use SpeedPuzzling\Web\Exceptions\NewsletterConfirmTokenExpired;
use SpeedPuzzling\Web\Message\ConfirmNewsletterSubscription;
use SpeedPuzzling\Web\Services\NewsletterTokenSigner;
use SpeedPuzzling\Web\Value\NewsletterAudience;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Double opt-in confirmation link from the newsletter confirmation e-mail.
 */
final class NewsletterConfirmController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
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
    )]
    public function __invoke(string $token): Response
    {
        try {
            $claim = $this->tokenSigner->parseConfirmToken($token);
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

        return $this->render('newsletter/confirmed.html.twig', [
            'isPlayer' => $claim->audience === NewsletterAudience::Player,
        ]);
    }

    private function renderInvalid(string $messageKey): Response
    {
        return $this->render('newsletter/invalid-token.html.twig', [
            'messageKey' => $messageKey,
        ], new Response(status: Response::HTTP_NOT_FOUND));
    }
}
