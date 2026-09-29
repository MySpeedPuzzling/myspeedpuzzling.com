<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public "how to delete your data" instructions - the URL Meta asks for as the
 * app's "User data deletion" instructions (social login), linked from the
 * privacy policy and the footer.
 */
final class DataDeletionController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/smazani-udaju',
            'en' => '/en/data-deletion',
            'es' => '/es/eliminacion-datos',
            'ja' => '/ja/データ削除',
            'fr' => '/fr/suppression-donnees',
            'de' => '/de/datenloeschung',
        ],
        name: 'data_deletion',
    )]
    public function __invoke(): Response
    {
        return $this->render('data-deletion.html.twig');
    }
}
