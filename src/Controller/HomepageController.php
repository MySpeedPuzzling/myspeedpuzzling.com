<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetLastSolvedPuzzle;
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
        readonly private GetLastSolvedPuzzle $getLastSolvedPuzzle,
        readonly private GetStatistics $getStatistics,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(path: '/', name: 'homepage_crossroads')]
    #[Route(
        path: [
            'cs' => '/uvod',
            'en' => '/en/home',
        ],
        name: 'homepage',
    )]
    public function __invoke(Request $request, #[CurrentUser] UserInterface|null $user): Response
    {
        $playerProfile = $this->retrieveLoggedUserProfile->getProfile();
        $locale = $request->getPreferredLanguage(['en', 'cs']) ?? 'en';

        if ($playerProfile !== null) {
            $locale = $playerProfile->locale ?? $locale;
        }

        if ($request->getPathInfo() === '/') {
            if ($user !== null) {
                return $this->redirectToRoute('hub', ['_locale' => $locale]);
            }

            return $this->redirectToRoute('homepage', ['_locale' => $locale]);
        }

        return $this->render('homepage.html.twig', [
            'last_solved_puzzles' => $this->getLastSolvedPuzzle->limit(5),
            'global_statistics' => $this->getStatistics->globally(),
        ]);
    }
}
