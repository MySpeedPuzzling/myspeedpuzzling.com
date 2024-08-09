<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class WjpcConnectionController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/wjpc-2024',
            'en' => '/en/wjpc-2024',
        ],
        name: 'wjpc2024',
    )]
    public function __invoke(#[CurrentUser] UserInterface|null $user): Response
    {
        return $this->render('wjpc2024.html.twig');
    }
}
