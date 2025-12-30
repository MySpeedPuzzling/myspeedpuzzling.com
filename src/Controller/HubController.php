<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HubController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/hub',
            'en' => '/en/hub',
            'es' => '/es/centro',
            'ja' => '/ja/ハブ',
            'fr' => '/fr/hub',
            'de' => '/de/zentrale',
        ],
        name: 'hub',
    )]
    public function __invoke(): Response
    {
        return $this->render('hub.html.twig');
    }
}
