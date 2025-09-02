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
            'ja' => '/ja/ブログ/2025-02-17/最大のmsp障害',
            'fr' => '/fr/blog/2025-02-17/la-plus-grande-panne-msp',
            'de' => '/de/blog/2025-02-17/groesster-msp-ausfall',
        ],
        name: 'blog',
    )]
    public function __invoke(): Response
    {
        return $this->render('blog.html.twig');
    }
}
