<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use Auth0\Symfony\Models\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class ImportCompetitionPuzzlersController extends AbstractController
{
    #[Route(
        path: '/admin/import-competition-puzzlers',
        name: 'admin_import_competition_puzzlers',
    )]
    public function __invoke(
        Request $request,
        #[CurrentUser] User $user,
    ): Response {
        return $this->render('admin/import_competition_puzzlers.html.twig');
    }
}
