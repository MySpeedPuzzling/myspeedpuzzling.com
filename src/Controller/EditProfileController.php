<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class EditProfileController extends AbstractController
{
    public function __construct(
    ) {
    }

    #[Route(path: '/upravit-profil', name: 'edit_profile', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        return $this->render('edit-profile.html.twig');
    }
}
