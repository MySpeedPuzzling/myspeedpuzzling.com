<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetStatistics;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class HomepageController extends AbstractController
{
    public function __construct(
        readonly private GetStatistics $getStatistics,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(path: '/', name: 'homepage_crossroads')]
    #[Route(
        path: [
            'cs' => '/uvod',
            'en' => '/en/home',
            'es' => '/es/inicio',
            'ja' => '/ja/ホーム',
            'fr' => '/fr/accueil',
            'de' => '/de/start',
        ],
        name: 'homepage',
    )]
    public function __invoke(Request $request, #[CurrentUser] null|UserInterface $user): Response
    {
        $playerProfile = $this->retrieveLoggedUserProfile->getProfile();
        $locale = $request->getPreferredLanguage(['en', 'cs', 'es', 'ja', 'fr', 'de']) ?? 'en';

        if ($playerProfile !== null) {
            $locale = $playerProfile->locale ?? $locale;
        }

        if ($request->getPathInfo() === '/') {
            if ($user !== null) {
                return $this->redirectToRoute('hub', ['_locale' => $locale]);
            }

            // Anonymous visitors get a real 200 language-crossroads page: the domain
            // root must be a page (not a redirect) for Google's site-name system,
            // and it anchors the hreflang x-default cluster. Returning visitors are
            // auto-continued client-side via their stored language preference.
            return $this->render('crossroads.html.twig', [
                'suggested_locale' => $locale,
            ]);
        }

        return $this->render('homepage.html.twig', [
            'global_statistics' => $this->getStatistics->globally(),
        ]);
    }
}
