<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FaqController extends AbstractController
{
    public function __construct()
    {
    }

    #[Route(
        path: [
            'cs' => '/caste-dotazy',
            'en' => '/en/faq',
        ],
        name: 'faq',
    )]
    public function __invoke(): Response
    {
        return $this->render('faq.html.twig');
    }
}
