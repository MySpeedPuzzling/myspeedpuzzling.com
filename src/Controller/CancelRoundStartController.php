<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Message\CancelRoundStart;
use SpeedPuzzling\Web\Security\OnlineRoundsVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Cancel the start" - a countdown called off before its moment (docs/features/online-competitions/README.md §5.1);
 * once the round runs it is refused (AbstractRoundStopwatchController: CSRF, refusals, JSON). Needs
 * ONLINE_ROUNDS_MANAGE too until the test cups are done.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class CancelRoundStartController extends AbstractRoundStopwatchController
{
    #[Route(
        path: [
            'cs' => '/stopky-kola/{roundId}/zrusit-start',
            'en' => '/en/round-stopwatch/{roundId}/cancel',
            'es' => '/es/round-stopwatch/{roundId}/cancel',
            'ja' => '/ja/round-stopwatch/{roundId}/cancel',
            'fr' => '/fr/round-stopwatch/{roundId}/cancel',
            'de' => '/de/round-stopwatch/{roundId}/cancel',
        ],
        name: 'cancel_round_start',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $roundId): Response
    {
        return $this->act(
            $request,
            $roundId,
            fn (CompetitionRound $round): CancelRoundStart => new CancelRoundStart(
                roundId: $round->id->toString(),
                actingPlayerId: $this->actingPlayerId(),
            ),
            'manage_round_stopwatch',
            // Online timing's prototype until the test cups are done (docs/features/feature_flags.md)
            requiredAttribute: OnlineRoundsVoter::ONLINE_ROUNDS_MANAGE,
        );
    }
}
