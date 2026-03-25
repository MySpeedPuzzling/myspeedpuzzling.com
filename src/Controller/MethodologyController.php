<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MethodologyController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/metodologie',
            'en' => '/en/methodology',
            'es' => '/es/metodologia',
            'ja' => '/ja/方法論',
            'fr' => '/fr/methodologie',
            'de' => '/de/methodik',
        ],
        name: 'methodology',
    )]
    public function __invoke(): Response
    {
        return $this->render('methodology/index.html.twig');
    }
}
