<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use SpeedPuzzling\Web\Query\GetEditionRounds;
use SpeedPuzzling\Web\Query\GetPuzzleDifficulty;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Results\EditionRoundDetail;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class EventDetailController extends AbstractController
{
    public function __construct(
        readonly private GetCompetitionEvents $getCompetitionEvents,
        readonly private GetCompetitionParticipants $getCompetitionParticipants,
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private GetPuzzleDifficulty $getPuzzleDifficulty,
        readonly private GetEditionRounds $getEditionRounds,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private IsCompetitionPubliclyVisible $isCompetitionPubliclyVisible,
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

        $competitionEvent = $this->getCompetitionEvents->byId($competition->id->toString());
        $puzzles = [];

        if ($competitionEvent->tagId !== null) {
            $puzzles = $this->getPuzzleOverview->byTagId($competitionEvent->tagId);
        }

        // Which round each puzzle was solved in. The query already applies the round's hide rules,
        // so a puzzle hidden until its round starts gets no round badge either.
        /** @var array<string, list<EditionRoundDetail>> $puzzleRounds */
        $puzzleRounds = [];
        /** @var array<string, string> $roundResultsUrls */
        $roundResultsUrls = [];
        foreach ($this->getEditionRounds->forCompetition($competition->id->toString()) as $round) {
            foreach ($round->puzzles as $roundPuzzle) {
                $puzzleRounds[$roundPuzzle->puzzleId][] = $round;
            }

            if ($round->slug !== null) {
                $roundResultsUrls[$round->id] = $this->generateUrl('event_round_results', [
                    'slug' => $competition->slug,
                    'roundSlug' => $round->slug,
                ]);
            }
        }

        // With rounds, the schedule is the natural order (rounds come sorted by start, so [0] is the
        // earliest); puzzles outside any round follow in their original order - usort is stable
        $firstRoundStart = static fn (PuzzleOverview $puzzle): null|DateTimeImmutable => isset($puzzleRounds[$puzzle->puzzleId])
            ? $puzzleRounds[$puzzle->puzzleId][0]->startsAt
            : null;

        usort($puzzles, static function (PuzzleOverview $a, PuzzleOverview $b) use ($firstRoundStart): int {
            $aStartsAt = $firstRoundStart($a);
            $bStartsAt = $firstRoundStart($b);

            if ($aStartsAt === null || $bStartsAt === null) {
                return ($aStartsAt === null) <=> ($bStartsAt === null);
            }

            return $aStartsAt <=> $bStartsAt;
        });

        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();

        $puzzleStatuses = $this->getUserPuzzleStatuses->byPlayerId($loggedPlayer?->playerId);

        $playerConnections = [];
        if ($loggedPlayer !== null) {
            $playerConnections = $this->getCompetitionParticipants->getPlayerConnections(
                $competition->id->toString(),
                $loggedPlayer->playerId,
            );
        }

        // "Add my time from this event" deep link: signed-in, the event is publicly visible (so the
        // add-time picker offers it) and it has already started — no times for an upcoming event.
        $canAddTime = $loggedPlayer !== null
            && $competitionEvent->startsAfter($this->clock->now()) === false
            && $this->isCompetitionPubliclyVisible->check($competitionEvent->id);

        return $this->render('event_detail.html.twig', [
            'event' => $competitionEvent,
            'puzzles' => $puzzles,
            'puzzle_rounds' => $puzzleRounds,
            'round_results_urls' => $roundResultsUrls,
            'difficulty_data' => $this->getPuzzleDifficulty->forPuzzleList(array_map(
                static fn (PuzzleOverview $puzzle): string => $puzzle->puzzleId,
                $puzzles,
            )),
            'puzzle_statuses' => $puzzleStatuses,
            'is_going' => count($playerConnections) > 0,
            'can_add_time' => $canAddTime,
        ]);
    }
}
