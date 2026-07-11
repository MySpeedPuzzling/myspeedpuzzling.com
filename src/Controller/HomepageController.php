<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Services\HomepageStatistics;
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
        readonly private HomepageStatistics $homepageStatistics,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/cs',
            'en' => '/',
            'es' => '/es',
            'ja' => '/ja',
            'fr' => '/fr',
            'de' => '/de',
        ],
        name: 'homepage',
    )]
    public function __invoke(Request $request, #[CurrentUser] null|UserInterface $user): Response
    {
        // Logged-in visitors hitting the domain root continue straight to their
        // hub (in their profile language); every other visitor gets the
        // marketing homepage - the domain root must be a real 200 page for
        // Google's site-name system and anchors the hreflang x-default cluster.
        if ($request->getPathInfo() === '/' && $user !== null) {
            $playerProfile = $this->retrieveLoggedUserProfile->getProfile();
            $locale = $request->getPreferredLanguage(['en', 'cs', 'es', 'ja', 'fr', 'de']) ?? 'en';

            if ($playerProfile !== null) {
                $locale = $playerProfile->locale ?? $locale;
            }

            return $this->redirectToRoute('hub', ['_locale' => $locale]);
        }

        return $this->render('homepage.html.twig', [
            'homepage_statistics' => $this->homepageStatistics->all(),
        ]);
    }
}
