<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ReviewResults;

use SpeedPuzzling\Web\Query\GetFirstTryTimes;
use SpeedPuzzling\Web\Query\GetPlayerDuplicateCases;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Review your results" (docs/features/duplicate-results.md, "Review page"): results saved twice, copies removed
 * automatically, then the first-try conflicts and late first tries (docs/features/first-try-integrity.md) -
 * always the signed-in player's own.
 */
final class ReviewResultsController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'review_results';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetPlayerDuplicateCases $getPlayerDuplicateCases,
        readonly private GetFirstTryTimes $getFirstTryTimes,
    ) {
    }

    #[Route(
        path: '/{_locale}/review-results',
        name: 'review_results',
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        return $this->render('review_results/index.html.twig', [
            'player_id' => $player->playerId,
            'duplicates' => $this->getPlayerDuplicateCases->openOf($player->playerId),
            'auto_removals' => $this->getPlayerDuplicateCases->autoRemovalsOf($player->playerId),
            'conflicts' => $this->getFirstTryTimes->conflictsOf($player->playerId),
            'late_first_tries' => $this->getFirstTryTimes->lateFirstTriesOf($player->playerId),
        ]);
    }
}
