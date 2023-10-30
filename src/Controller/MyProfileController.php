<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class MyProfileController extends AbstractController
{
    #[Route(path: '/my-profile', name: 'my_profile', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        return $this->render('my-profile.html.twig');
    }
}
