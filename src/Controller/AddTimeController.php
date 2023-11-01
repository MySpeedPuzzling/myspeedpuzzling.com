<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class AddTimeController extends AbstractController
{
    #[Route(path: '/pridat-cas', name: 'add_time', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        return $this->render('add-time.html.twig');
    }
}
