<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetPuzzleResultDetail;
use SpeedPuzzling\Web\Services\ResolveDifficultyTiers;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * One player's - or one exact pair's / team's - results on one puzzle, opened from one of its times.
 * A modal when opened into the global modal frame, a full (noindex) page otherwise.
 * See docs/features/puzzle-result-detail.md.
 */
final class PuzzleResultDetailController extends AbstractController
{
    public function __construct(
        readonly private GetPuzzleResultDetail $getPuzzleResultDetail,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private ResolveDifficultyTiers $resolveDifficultyTiers,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/vysledek/{timeId}',
            'en' => '/en/result/{timeId}',
            'es' => '/es/resultado/{timeId}',
            'ja' => '/ja/結果/{timeId}',
            'fr' => '/fr/resultat/{timeId}',
            'de' => '/de/ergebnis/{timeId}',
        ],
        name: 'puzzle_result_detail',
        // Any uuid-shaped id, not only valid RFC 4122 versions (Requirement::UUID): the fixture times are not,
        // and the leaderboard links every one of its rows here
        requirements: ['timeId' => Requirement::UID_RFC4122],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $timeId): Response
    {
        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        $result = $this->getPuzzleResultDetail->byTimeId($timeId, $viewer?->playerId);

        $template = $request->headers->get('Turbo-Frame') === 'modal-frame'
            ? 'puzzle_result/_modal.html.twig'
            : 'puzzle_result/detail.html.twig';

        $response = $this->render($template, [
            'result' => $result,
            // Members see the puzzle's difficulty on its image (null for everyone else)
            'difficulty_tiers' => $this->resolveDifficultyTiers->forViewer($viewer, [$result->puzzleId]),
        ]);

        // Same URL, two bodies: a cache must never hand the modal to a full-page visit or the other way round
        $response->setVary('Turbo-Frame', false);

        return $response;
    }
}
