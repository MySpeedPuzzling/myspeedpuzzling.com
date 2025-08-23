<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BlogController extends AbstractController
{
    public function __construct()
    {
    }

    #[Route(
        path: [
            'cs' => '/blog/2025-02-17/nejvetsi-msp-vypadek',
            'en' => '/en/blog/2025-02-17/the-biggest-msp-outage',
            'es' => '/es/blog/2025-02-17/la-mayor-interrupcion-msp',
        ],
        name: 'blog',
    )]
    public function __invoke(): Response
    {
        return $this->render('blog.html.twig');
    }
}
