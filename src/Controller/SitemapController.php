<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetPuzzleSolvers;
use SpeedPuzzling\Web\Query\GetPuzzlesOverview;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Query\GetTags;
use SpeedPuzzling\Web\Query\GetUserSolvedPuzzles;
use SpeedPuzzling\Web\Results\PuzzleSolver;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SitemapController extends AbstractController
{
    public function __construct(
        readonly private GetPuzzlesOverview $getPuzzlesOverview,
    ) {
    }

    #[Route(path: '/sitemap.xml')]
    public function __invoke(): Response
    {
        /** @var array<string, array<string, string>> $urls */
        $urls = [
            'homepage_crossroads' => [
                'en' => $this->generateUrl('homepage_crossroads', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ],
        ];

        $staticRoutes = ['homepage', 'contact', 'faq', 'ladder', 'ladder_solo_500_pieces', 'ladder_solo_1000_pieces', 'ladder_pairs_500_pieces', 'ladder_pairs_1000_pieces', 'ladder_groups_500_pieces', 'ladder_groups_1000_pieces', 'privacy_policy', 'puzzles', 'players', 'recent_activity', 'terms_of_service', 'hub', 'scan', 'wjpc2024'];

        foreach ($staticRoutes as $route) {
            foreach (['cs', 'en'] as $locale) {
                $urls[$route][$locale] = $this->generateUrl($route, ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL);
            }
        }

        foreach (['cs', 'en'] as $locale) {
            foreach ($this->getPuzzlesOverview->allApprovedOrAddedByPlayer(null) as $puzzles) {
                $urls[$puzzles->puzzleId][$locale] = $this->generateUrl('puzzle_detail', [
                    '_locale' => $locale,
                    'puzzleId' => $puzzles->puzzleId,
                ], UrlGeneratorInterface::ABSOLUTE_URL);
            }

            foreach (CountryCode::cases() as $countryCode) {
                $urls['players_per_country_' . $countryCode->name][$locale] = $this->generateUrl('players_per_country', [
                    '_locale' => $locale,
                    'countryCode' => $countryCode->name,
                ], UrlGeneratorInterface::ABSOLUTE_URL);
            }
        }

        $response = new Response(headers: [
            'Content-Type', 'text/xml'
        ]);

        return $this->render('sitemap.xml.twig', [
            'urls' => $urls,
        ], $response);
    }
}
