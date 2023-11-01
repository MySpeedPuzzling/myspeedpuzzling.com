<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class LadderController extends AbstractController
{
    #[Route(path: '/zebricek', name: 'ladder', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        return $this->render('ladder.html.twig');
    }
}
