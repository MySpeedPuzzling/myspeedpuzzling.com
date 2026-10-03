<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ComparisonKind;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The old 1:1 page "/compare-with-puzzler/{id}/" (D9 of docs/features/player-comparison.md): links and bookmarks to it
 * land on the comparison of you and that player as a preview - nothing is added to the line-up until the viewer says
 * so. A GET never writes.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class LegacyComparePlayersController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetPlayerProfile $getPlayerProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/porovnat-s-puzzlerem/{opponentPlayerId}/',
            'en' => '/compare-with-puzzler/{opponentPlayerId}/',
            'es' => '/es/comparar-con-puzzlero/{opponentPlayerId}/',
            'ja' => '/ja/比較/{opponentPlayerId}/',
            'fr' => '/fr/comparer-avec-puzzleur/{opponentPlayerId}/',
            'de' => '/de/vergleich-mit-puzzler/{opponentPlayerId}/',
        ],
        name: 'compare_players',
        methods: ['GET'],
    )]
    public function __invoke(string $opponentPlayerId): Response
    {
        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        if ($viewer === null) {
            return $this->redirectToRoute('my_profile');
        }

        // Unknown, or hidden from the viewer by the blocklist → 404, like the player's profile
        $opponent = $this->getPlayerProfile->byId($opponentPlayerId);

        $refs = [ComparisonSubjectRef::player($viewer->playerId)->toString()];

        if ($opponent->playerId !== $viewer->playerId) {
            $refs[] = ComparisonSubjectRef::player($opponent->playerId)->toString();
        }

        return $this->redirectToRoute('comparison', [
            'kind' => ComparisonKind::Solo->value,
            'with' => implode(',', $refs),
        ]);
    }
}
