<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetRoundResultsOverview;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Results\RoundStopwatchState;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Value\RoundTimezone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The round stopwatch's control page (docs/features/competitions-management/README.md "Round Stopwatch"): its clock as
 * every device shows it, and the actions the clock allows now - start (now, in 10 s, at a chosen moment of the round's
 * zone), cancel a countdown, stop, reset (AbstractRoundStopwatchController). round_stopwatch_controller.js keeps the
 * clock and the buttons current while the page stays open.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ManageRoundStopwatchController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly GetRoundResultsOverview $getRoundResultsOverview,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/sprava-stopek-kola/{roundId}',
            'en' => '/en/manage-round-stopwatch/{roundId}',
            'es' => '/es/manage-round-stopwatch/{roundId}',
            'ja' => '/ja/manage-round-stopwatch/{roundId}',
            'fr' => '/fr/manage-round-stopwatch/{roundId}',
            'de' => '/de/manage-round-stopwatch/{roundId}',
        ],
        name: 'manage_round_stopwatch',
    )]
    public function __invoke(string $roundId): Response
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $competitionId = $round->competition->id->toString();
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        // The seating step before an in-person round starts (docs/features/competitions-management/seating.md)
        $roundOverview = $this->getRoundResultsOverview->forRound($roundId);
        $now = $this->clock->now();
        $timezone = $round->displayTimezone();

        return $this->render('manage_round_stopwatch.html.twig', [
            'round' => $round,
            'competition' => $round->competition,
            'round_overview' => $roundOverview,
            'stopwatch' => RoundStopwatchState::ofRound($round, $now),
            'server_now_ms' => RoundStopwatchState::milliseconds($now),
            'state_url' => $this->generateUrl('round_stopwatch_state', ['roundId' => $round->id->toString()]),
            'timezone' => $timezone,
            // "Start at": the round's planned start while it lies ahead, else the next whole minute but one - in the
            // round's zone, the zone the organiser reads every start of the round in
            'start_at_value' => RoundTimezone::toLocal(self::startAtSuggestion($round->startsAt, $now), $timezone)->format('Y-m-d\TH:i:s'),
            'start_at_min' => RoundTimezone::toLocal($now, $timezone)->format('Y-m-d\TH:i'),
        ]);
    }

    private static function startAtSuggestion(DateTimeImmutable $plannedStart, DateTimeImmutable $now): DateTimeImmutable
    {
        if ($plannedStart > $now) {
            return $plannedStart;
        }

        return $now->setTimestamp(intdiv($now->getTimestamp(), 60) * 60 + 120);
    }
}
