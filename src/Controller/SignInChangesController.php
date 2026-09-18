<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The old "sign-in is moving" explainer URLs (Auth0 migration, issue #147). The
 * page itself retired with the Auth0 stack in Phase 6; the announcement email,
 * the newsletter and support replies still link here, so the six locale paths
 * land on the sign-in page instead of a 404.
 */
final class SignInChangesController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/prihlasovani-se-stehuje',
            'en' => '/en/sign-in-is-moving',
            'es' => '/es/el-inicio-de-sesion-se-muda',
            'ja' => '/ja/サインインの移行',
            'fr' => '/fr/la-connexion-demenage',
            'de' => '/de/anmeldung-zieht-um',
        ],
        name: 'sign_in_changes',
    )]
    public function __invoke(): Response
    {
        return $this->redirectToRoute('login', status: RedirectResponse::HTTP_MOVED_PERMANENTLY);
    }
}
