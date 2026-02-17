<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Marketplace;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MarketplaceHowItWorksController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/marketplace/jak-to-funguje',
            'en' => '/en/marketplace/how-it-works',
            'es' => '/es/marketplace/como-funciona',
            'ja' => '/ja/marketplace/how-it-works',
            'fr' => '/fr/marketplace/comment-ca-marche',
            'de' => '/de/marketplace/wie-es-funktioniert',
        ],
        name: 'marketplace_how_it_works',
        methods: ['GET'],
    )]
    public function __invoke(): Response
    {
        return $this->render('marketplace/how_it_works.html.twig');
    }
}
