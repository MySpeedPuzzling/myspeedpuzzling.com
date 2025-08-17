<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class EditPlayerCodeController extends AbstractController
{
     #[Route(
         path: [
            'cs' => '/upravit-kod-hrace',
            'en' => '/en/edit-player-code',
            'es' => '/es/editar-codigo-jugador',
         ],
         name: 'edit_player_code',
     )]
    public function __invoke(Request $request, #[CurrentUser] User $user): Response
    {
        return $this->redirectToRoute('edit_profile');
    }
}
