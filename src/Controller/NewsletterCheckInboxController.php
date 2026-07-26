<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Services\NewsletterTokenSigner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class NewsletterCheckInboxController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/newsletter/zkontrolujte-schranku',
            'en' => '/en/newsletter/check-your-inbox',
            'es' => '/es/newsletter/revisa-tu-correo',
            'ja' => '/ja/newsletter/check-your-inbox',
            'fr' => '/fr/newsletter/verifiez-votre-boite',
            'de' => '/de/newsletter/postfach-pruefen',
        ],
        name: 'newsletter_check_inbox',
    )]
    public function __invoke(): Response
    {
        return $this->render('newsletter/check-inbox.html.twig', [
            'expiresHours' => NewsletterTokenSigner::CONFIRM_LIFETIME_HOURS,
        ]);
    }
}
