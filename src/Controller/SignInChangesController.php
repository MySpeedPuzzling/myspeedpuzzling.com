<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public explainer for the Auth0 -> native sign-in migration (issue #147,
 * communication-plan §FAQ). Published ahead of every stage so the site-wide
 * notice has somewhere to point long before anything actually changes, and it
 * stays up through the cutover - dormant players return months later.
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
        return $this->render('sign_in_changes.html.twig');
    }
}
