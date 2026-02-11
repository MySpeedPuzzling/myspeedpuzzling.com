<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Marketplace;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MarketplaceController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/marketplace',
            'en' => '/en/marketplace',
            'es' => '/es/marketplace',
            'ja' => '/ja/marketplace',
            'fr' => '/fr/marketplace',
            'de' => '/de/marketplace',
        ],
        name: 'marketplace',
        methods: ['GET'],
    )]
    public function __invoke(): Response
    {
        return $this->render('marketplace/index.html.twig');
    }
}
