<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Message\ScheduleRoundStart;
use SpeedPuzzling\Web\Security\OnlineRoundsVoter;
use SpeedPuzzling\Web\Value\ScheduledRoundStart;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A start at a moment ahead - the countdown every device shows by itself (docs/features/online-competitions/README.md
 * §5.1): `countdown=10` ("Start in 10 s" - the next whole second at least that far ahead) or `at=<ISO>` (a moment with
 * its offset, or a wall time of the round's zone as the control page's datetime-local input sends it) -
 * ScheduledRoundStart. Then the control page (AbstractRoundStopwatchController: CSRF, refusals, JSON). Needs
 * ONLINE_ROUNDS_MANAGE too until the test cups are done.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ScheduleRoundStartController extends AbstractRoundStopwatchController
{
    #[Route(
        path: [
            'cs' => '/stopky-kola/{roundId}/naplanovat-start',
            'en' => '/en/round-stopwatch/{roundId}/schedule',
            'es' => '/es/round-stopwatch/{roundId}/schedule',
            'ja' => '/ja/round-stopwatch/{roundId}/schedule',
            'fr' => '/fr/round-stopwatch/{roundId}/schedule',
            'de' => '/de/round-stopwatch/{roundId}/schedule',
        ],
        name: 'schedule_round_start',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $roundId): Response
    {
        return $this->act(
            $request,
            $roundId,
            function (CompetitionRound $round) use ($request): ScheduleRoundStart {
                $countdown = trim($request->request->getString('countdown'));

                $startsAt = $countdown !== ''
                    ? ScheduledRoundStart::inSeconds($countdown, $this->now())
                    : ScheduledRoundStart::at($request->request->getString('at'), $round->displayTimezone());

                return new ScheduleRoundStart(
                    roundId: $round->id->toString(),
                    startsAt: $startsAt,
                    actingPlayerId: $this->actingPlayerId(),
                );
            },
            'manage_round_stopwatch',
            // Online timing's prototype until the test cups are done (docs/features/feature_flags.md)
            requiredAttribute: OnlineRoundsVoter::ONLINE_ROUNDS_MANAGE,
        );
    }
}
