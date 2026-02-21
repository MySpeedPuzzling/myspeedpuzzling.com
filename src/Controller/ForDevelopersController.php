<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ForDevelopersController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/pro-vyvojare',
            'en' => '/en/for-developers',
            'es' => '/es/para-desarrolladores',
            'ja' => '/ja/開発者向け',
            'fr' => '/fr/pour-developpeurs',
            'de' => '/de/fuer-entwickler',
        ],
        name: 'for_developers',
    )]
    public function __invoke(): Response
    {
        return $this->render('for-developers.html.twig');
    }
}
