<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OnlineRounds;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Controller\AbstractRoundStopwatchController;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\OnlineRoundNotFound;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Security\OnlineRoundsVoter;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineLiveState;
use SpeedPuzzling\Web\Value\RoundTimezone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The control room of an online round (docs/features/online-competitions/implementation-plan.md WP 1.7, contract §5
 * `online_control_room`): the round's clock as every phone shows it, "Start in 10 s" / "Start at …" / "Cancel the
 * start" / "End now" (the round stopwatch's own actions, asked for JSON), and the grid of participants - ready, camera,
 * clock quality, last seen, the latest photo, finished or the pieces reported. The page bootstraps the state
 * `online_live` answers and polls it every few seconds while it is shown (assets/controllers/online_control_controller.js).
 *
 * Organisers of the event for whom online rounds are on (COMPETITION_EDIT + ONLINE_ROUNDS_MANAGE), rounds with online
 * timing only. Never cached - it carries photo URLs.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class OnlineControlRoomController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly OnlineRoundRepository $onlineRoundRepository,
        private readonly OnlineLiveState $onlineLiveState,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/online-kola/{roundId}/ridici-mistnost',
            'en' => '/en/online-rounds/{roundId}/control',
            'es' => '/es/online-rounds/{roundId}/control',
            'ja' => '/ja/online-rounds/{roundId}/control',
            'fr' => '/fr/online-rounds/{roundId}/control',
            'de' => '/de/online-rounds/{roundId}/control',
        ],
        name: 'online_control_room',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(string $roundId): Response
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $competitionId = $round->competition->id->toString();
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);
        $this->denyAccessUnlessGranted(OnlineRoundsVoter::ONLINE_ROUNDS_MANAGE, $competitionId);

        $roundId = $round->id->toString();
        $onlineRound = $this->onlineRoundRepository->ofRound($roundId) ?? throw new OnlineRoundNotFound();
        $now = $this->clock->now();
        $timezone = $round->displayTimezone();

        $response = $this->render('online_rounds/control.html.twig', [
            'competition' => $round->competition,
            'round' => $round,
            'online_round' => $onlineRound,
            'state' => $this->onlineLiveState->forRound($round),
            'timezone' => $timezone,
            'csrf_token_id' => AbstractRoundStopwatchController::CSRF_TOKEN_ID,
            // "Start at": the round's planned start while it lies ahead, else the next whole minute but one - in the
            // round's zone (the zone the stopwatch control page reads every start of the round in)
            'start_at_value' => RoundTimezone::toLocal(self::startAtSuggestion($round->startsAt, $now), $timezone)->format('Y-m-d\TH:i:s'),
            'start_at_min' => RoundTimezone::toLocal($now, $timezone)->format('Y-m-d\TH:i'),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private static function startAtSuggestion(DateTimeImmutable $plannedStart, DateTimeImmutable $now): DateTimeImmutable
    {
        if ($plannedStart > $now) {
            return $plannedStart;
        }

        return $now->setTimestamp(intdiv($now->getTimestamp(), 60) * 60 + 120);
    }
}
