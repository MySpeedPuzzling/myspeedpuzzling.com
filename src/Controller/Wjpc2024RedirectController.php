<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The WJPC 2024 page was removed - permanently redirect its URLs
 * to the events listing so accumulated link equity is not lost.
 */
final class Wjpc2024RedirectController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/wjpc-2024',
            'en' => '/en/wjpc-2024',
        ],
        name: 'wjpc_2024',
    )]
    public function __invoke(): Response
    {
        return $this->redirectToRoute('events', [], Response::HTTP_MOVED_PERMANENTLY);
    }
}
