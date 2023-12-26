<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class FaqController extends AbstractController
{
    public function __construct(
    ) {
    }

    #[Route(path: '/caste-dotazy', name: 'faq', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('faq.html.twig');
    }
}
