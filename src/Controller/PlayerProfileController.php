<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetAffiliateSupporters;
use SpeedPuzzling\Web\Query\GetGettingStartedProgress;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetPlayerReviewCounts;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\PlayerResultsCriteria;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class PlayerProfileController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetAffiliateSupporters $getAffiliateSupporters,
        readonly private GetGettingStartedProgress $getGettingStartedProgress,
        readonly private GetPlayerReviewCounts $getPlayerReviewCounts,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/profil-hrace/{playerId}',
            'en' => '/en/player-profile/{playerId}',
            'es' => '/es/perfil-jugador/{playerId}',
            'ja' => '/ja/プレイヤー-プロフィール/{playerId}',
            'fr' => '/fr/profil-joueur/{playerId}',
            'de' => '/de/spieler-profil/{playerId}',
        ],
        name: 'player_profile',
    )]
    public function __invoke(Request $request, string $playerId, #[CurrentUser] null|UserInterface $user): Response
    {
        // The list narrowed to one pair/team or tab is the Results page now (docs/features/navigation PROF-05e): old
        // links (team pages, shared URLs) land there - before anything is read; Results answers an unknown, hidden or
        // private player itself
        if ($request->query->has('team') || $request->query->has('category')) {
            return $this->redirectToRoute('player_results', ['playerId' => $playerId, ...self::resultsFilter($request)]);
        }

        // PlayerNotFound extends NotFoundHttpException and bubbles up as 404
        $player = $this->getPlayerProfile->byId($playerId);

        $loggedProfile = $this->retrieveLoggedUserProfile->getProfile();

        $affiliateSupporters = null;
        if ($player->isInReferralProgram()) {
            $affiliateSupporters = $this->getAffiliateSupporters->byPlayerId($player->playerId);
        }

        // A newcomer's own profile is mostly empty - the same "Getting started" card as on the Hub
        // gives it somewhere to go (docs/features/getting-started-guide.md)
        $gettingStarted = null;
        $reviewCounts = null;
        if ($loggedProfile !== null && $loggedProfile->playerId === $player->playerId) {
            // "Review your results" banner (docs/features/duplicate-results.md) - only the owner pays for it
            $reviewCounts = $this->getPlayerReviewCounts->forPlayer($player->playerId);

            $progress = $this->getGettingStartedProgress->forPlayer($loggedProfile);

            if ($progress->shouldBeShown() && $progress->isComplete() === false) {
                $gettingStarted = $progress;
            }
        }

        return $this->render('player_profile.html.twig', [
            'getting_started' => $gettingStarted,
            'review_counts' => $reviewCounts,
            'player' => $player,
            'affiliate_supporters' => $affiliateSupporters,
        ]);
    }

    /**
     * The profile's old `?team=<id>&category=duo|group` as Results parameters: a pair/team id only when it is one, its
     * tab Pairs unless Teams was named (the profile read it the same way); a tab without a pair/team is kept as it is
     *
     * @return array{category?: string, team?: string}
     */
    private static function resultsFilter(Request $request): array
    {
        $query = $request->query->all();
        $team = is_string($query['team'] ?? null) && Uuid::isValid($query['team']) ? $query['team'] : null;
        $category = is_string($query['category'] ?? null) && in_array($query['category'], PlayerResultsCriteria::CATEGORIES, true) ? $query['category'] : null;

        if ($team !== null) {
            return [
                'category' => $category === PlayerResultsCriteria::CATEGORY_GROUP ? PlayerResultsCriteria::CATEGORY_GROUP : PlayerResultsCriteria::CATEGORY_DUO,
                'team' => $team,
            ];
        }

        return $category !== null ? ['category' => $category] : [];
    }
}
