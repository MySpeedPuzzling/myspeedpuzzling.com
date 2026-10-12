<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What the Add tab opens for a guest (docs/features/navigation README §4.6 / §4.10, ADD-05): free tracking explained,
 * Join, Sign in back to the add page and the tracker app. A signed-in player goes straight to the add page
 * (implementation plan decision 41). No form, no session, no flash: the guest page stays shared-cacheable.
 */
final class AddIntroController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/pridat',
            'en' => '/en/add',
            'es' => '/es/anadir',
            'ja' => '/ja/追加',
            'fr' => '/fr/ajouter',
            'de' => '/de/eintragen',
        ],
        name: 'add_intro',
        methods: ['GET'],
    )]
    public function __invoke(): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('puzzle_add');
        }

        return $this->render('add_intro.html.twig');
    }
}
