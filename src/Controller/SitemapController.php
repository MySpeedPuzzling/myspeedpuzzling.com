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

    #[Route(path: '/sitemap.xml', methods: ['GET'])]
    public function __invoke(): Response
    {
        /** @var array<string> $urls */
        $urls = [
            $this->generateUrl('homepage_crossroads', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ];

        foreach (['cs', 'en'] as $locale) {
            $urls[] = $this->generateUrl('homepage', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL);
            $urls[] = $this->generateUrl('contact', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL);
            $urls[] = $this->generateUrl('faq', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL);
            $urls[] = $this->generateUrl('ladder', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL);
            $urls[] = $this->generateUrl('ladder_solo_500_pieces', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL);
            $urls[] = $this->generateUrl('ladder_solo_1000_pieces', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL);
            $urls[] = $this->generateUrl('ladder_pairs_500_pieces', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL);
            $urls[] = $this->generateUrl('ladder_pairs_1000_pieces', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL);
            $urls[] = $this->generateUrl('ladder_groups_500_pieces', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL);
            $urls[] = $this->generateUrl('ladder_groups_1000_pieces', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL);
            $urls[] = $this->generateUrl('privacy_policy', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL);
            $urls[] = $this->generateUrl('puzzles', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL);
            $urls[] = $this->generateUrl('players', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL);
            $urls[] = $this->generateUrl('recent_activity', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL);
            $urls[] = $this->generateUrl('terms_of_service', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL);
            $urls[] = $this->generateUrl('hub', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL);

            foreach ($this->getPuzzlesOverview->allApprovedOrAddedByPlayer(null) as $puzzles) {
                $urls[] = $this->generateUrl('puzzle_detail', [
                    '_locale' => $locale,
                    'puzzleId' => $puzzles->puzzleId,
                ], UrlGeneratorInterface::ABSOLUTE_URL);
            }

            foreach (CountryCode::cases() as $countryCode) {
                $urls[] = $this->generateUrl('players_per_country', [
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
