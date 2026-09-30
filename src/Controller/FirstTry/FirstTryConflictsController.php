<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\FirstTry;

use SpeedPuzzling\Web\Query\GetFirstTryTimes;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "First-try conflicts": the player's puzzles with more than one first try, one choice each - plus, quieter,
 * first tries logged after an earlier solve (docs/features/first-try-integrity.md).
 */
final class FirstTryConflictsController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'first_try_conflicts';

    // Any uuid - older puzzles and results do not all carry an RFC version digit
    public const string ID_REQUIREMENT = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetFirstTryTimes $getFirstTryTimes,
    ) {
    }

    #[Route(
        path: '/{_locale}/first-try-conflicts',
        name: 'first_try_conflicts',
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        return $this->render('first_try/conflicts.html.twig', [
            'player_id' => $player->playerId,
            'conflicts' => $this->getFirstTryTimes->conflictsOf($player->playerId),
            'late_first_tries' => $this->getFirstTryTimes->lateFirstTriesOf($player->playerId),
        ]);
    }
}
