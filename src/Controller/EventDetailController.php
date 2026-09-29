<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Query\CountCompetitionResults;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use SpeedPuzzling\Web\Query\GetCompetitionPuzzles;
use SpeedPuzzling\Web\Query\GetEditionRounds;
use SpeedPuzzling\Web\Query\GetPuzzleDifficulty;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Results\EditionRoundDetail;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\EventTitle;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class EventDetailController extends AbstractController
{
    public function __construct(
        readonly private GetCompetitionEvents $getCompetitionEvents,
        readonly private GetCompetitionPuzzles $getCompetitionPuzzles,
        readonly private GetCompetitionParticipants $getCompetitionParticipants,
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private GetPuzzleDifficulty $getPuzzleDifficulty,
        readonly private GetEditionRounds $getEditionRounds,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private IsCompetitionPubliclyVisible $isCompetitionPubliclyVisible,
        readonly private CountCompetitionResults $countCompetitionResults,
        readonly private ClockInterface $clock,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/eventy/{slug}',
            'en' => '/en/events/{slug}',
            'es' => '/es/eventos/{slug}',
            'ja' => '/ja/イベント/{slug}',
            'fr' => '/fr/evenements/{slug}',
            'de' => '/de/veranstaltungen/{slug}',
        ],
        name: 'event_detail',
    )]
    public function __invoke(
        #[MapEntity(mapping: ['slug' => 'slug'])] Competition $competition,
        #[CurrentUser] null|UserInterface $user,
    ): Response {
        if ($competition->series !== null && $competition->series->slug !== null && $competition->slug !== null) {
            return $this->redirectToRoute('edition_detail', [
                'seriesSlug' => $competition->series->slug,
                'editionSlug' => $competition->slug,
            ], Response::HTTP_MOVED_PERMANENTLY);
        }

        $competitionId = $competition->id->toString();
        $competitionEvent = $this->getCompetitionEvents->byId($competitionId);
        $rounds = $this->getEditionRounds->forCompetition($competitionId);
        $eventTitle = EventTitle::forCompetition($competitionEvent, null, $rounds, $this->clock->now());
        $isPubliclyVisible = $this->isCompetitionPubliclyVisible->check($competitionId);

        $puzzles = [];

        if ($competitionEvent->tagId !== null) {
            $puzzles = $this->getPuzzleOverview->byTagId($competitionEvent->tagId);
        }

        // Many organisers never tag their puzzles: then the puzzles of the event's rounds
        if ($puzzles === []) {
            $puzzles = $this->getCompetitionPuzzles->roundPuzzleOverviews($competitionId);
        }

        // Which round each puzzle was solved in. The query already applies the round's hide rules,
        // so a puzzle hidden until its round starts gets no round badge either.
        /** @var array<string, list<EditionRoundDetail>> $puzzleRounds */
        $puzzleRounds = [];
        /** @var array<string, string> $roundResultsUrls */
        $roundResultsUrls = [];
        foreach ($rounds as $round) {
            foreach ($round->puzzles as $roundPuzzle) {
                $puzzleRounds[$roundPuzzle->puzzleId][] = $round;
            }

            // Round results pages of an event that is not public answer 404 - no links to them
            if ($round->slug !== null && $isPubliclyVisible) {
                $roundResultsUrls[$round->id] = $this->generateUrl('event_round_results', [
                    'slug' => $competition->slug,
                    'roundSlug' => $round->slug,
                ]);
            }
        }

        // "Results by round": the rounds whose results page has something to show, in schedule order
        $resultsPerRound = $roundResultsUrls !== [] ? $this->countCompetitionResults->perRound($competitionId) : [];
        $resultRounds = array_values(array_filter(
            $rounds,
            static fn (EditionRoundDetail $round): bool => isset($roundResultsUrls[$round->id]) && ($resultsPerRound[$round->id] ?? 0) > 0,
        ));

        // Latest round first - during a multi-day event the round just played is what visitors look
        // for. Rounds come sorted by start, so the last one is a puzzle's most recent use; puzzles
        // outside any round follow in their original order - usort is stable
        $latestRoundStart = static function (PuzzleOverview $puzzle) use ($puzzleRounds): null|DateTimeImmutable {
            $rounds = $puzzleRounds[$puzzle->puzzleId] ?? [];

            return $rounds === [] ? null : $rounds[count($rounds) - 1]->startsAt;
        };

        usort($puzzles, static function (PuzzleOverview $a, PuzzleOverview $b) use ($latestRoundStart): int {
            $aStartsAt = $latestRoundStart($a);
            $bStartsAt = $latestRoundStart($b);

            if ($aStartsAt === null || $bStartsAt === null) {
                return ($aStartsAt === null) <=> ($bStartsAt === null);
            }

            return $bStartsAt <=> $aStartsAt;
        });

        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();

        $puzzleStatuses = $this->getUserPuzzleStatuses->byPlayerId($loggedPlayer?->playerId);

        $playerConnections = [];
        if ($loggedPlayer !== null) {
            $playerConnections = $this->getCompetitionParticipants->getPlayerConnections(
                $competitionId,
                $loggedPlayer->playerId,
            );
        }

        // "Add my time from this event" deep link: signed-in, the event is publicly visible (so the
        // add-time picker offers it) and it has already started — no times for an upcoming event.
        $canAddTime = $loggedPlayer !== null
            && $competitionEvent->startsAfter($this->clock->now()) === false
            && $isPubliclyVisible;

        return $this->render('event_detail.html.twig', [
            'event' => $competitionEvent,
            'event_title' => $eventTitle,
            // Only a past event's meta description quotes the number of results
            'results_count' => $eventTitle->isPast ? $this->countCompetitionResults->forCompetition($competitionId) : 0,
            'puzzles' => $puzzles,
            'puzzle_rounds' => $puzzleRounds,
            'round_results_urls' => $roundResultsUrls,
            'result_rounds' => $resultRounds,
            'difficulty_data' => $this->getPuzzleDifficulty->forPuzzleList(array_map(
                static fn (PuzzleOverview $puzzle): string => $puzzle->puzzleId,
                $puzzles,
            )),
            'puzzle_statuses' => $puzzleStatuses,
            'is_going' => count($playerConnections) > 0,
            // "Change" only makes sense while the organizer's list still has someone to switch to
            'can_change_participant' => count($playerConnections) > 0
                && $this->getCompetitionParticipants->hasNotConnectedParticipants($competitionId),
            'can_add_time' => $canAddTime,
        ]);
    }
}
