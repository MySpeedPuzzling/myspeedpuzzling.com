<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Query\CountCompetitionResults;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionPageSections;
use SpeedPuzzling\Web\Query\GetCompetitionPuzzles;
use SpeedPuzzling\Web\Query\GetCompetitionRegistrationOverview;
use SpeedPuzzling\Web\Query\GetEditionRounds;
use SpeedPuzzling\Web\Query\GetEventAttendance;
use SpeedPuzzling\Web\Query\GetEventOffers;
use SpeedPuzzling\Web\Query\GetOfficialRoundResults;
use SpeedPuzzling\Web\Query\GetPuzzleDifficulty;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Results\EditionRoundDetail;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Services\EventJustJoinedFlash;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\EventTitle;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class EventDetailController extends AbstractController
{
    // An event entered without rounds lists the puzzles people logged times for. A championship has
    // at most ~20 of them; a perpetual online event collects hundreds - the most logged ones are enough,
    // the page must not turn into a catalogue above the participants
    private const int SOLVED_PUZZLES_LIMIT = 24;

    public function __construct(
        readonly private GetCompetitionEvents $getCompetitionEvents,
        readonly private GetCompetitionPuzzles $getCompetitionPuzzles,
        readonly private GetEventAttendance $getEventAttendance,
        readonly private GetEventOffers $getEventOffers,
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private GetPuzzleDifficulty $getPuzzleDifficulty,
        readonly private GetEditionRounds $getEditionRounds,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private IsCompetitionPubliclyVisible $isCompetitionPubliclyVisible,
        readonly private CountCompetitionResults $countCompetitionResults,
        readonly private GetCompetitionRegistrationOverview $getCompetitionRegistrationOverview,
        readonly private GetOfficialRoundResults $getOfficialRoundResults,
        readonly private GetCompetitionPageSections $getCompetitionPageSections,
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
        // An edition's slug is unique only within its series - a standalone event holding the slug wins
        #[MapEntity(expr: 'repository.findOneBy({"slug": slug}, {"series": "DESC"})')] Competition $competition,
        #[CurrentUser] null|UserInterface $user,
        Request $request,
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

        // Many organisers never tag their puzzles: then the puzzles of the event's rounds, and for an event
        // entered without rounds the puzzles people logged times for there
        if ($puzzles === []) {
            $puzzles = $this->getCompetitionPuzzles->roundPuzzleOverviews($competitionId);
        }

        if ($puzzles === []) {
            $puzzles = $this->getCompetitionPuzzles->solvedPuzzleOverviews($competitionId, self::SOLVED_PUZZLES_LIMIT);
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

        // "Add my time from this event" deep link: signed-in, the event is publicly visible (so the
        // add-time picker offers it) and it has already started — no times for an upcoming event.
        $canAddTime = $loggedPlayer !== null
            && $competitionEvent->startsAfter($this->clock->now()) === false
            && $isPubliclyVisible;

        // Marketplace card: one query on a marketplace event, none anywhere else (docs/features/marketplace/11-events.md)
        $eventOffers = $this->getEventOffers->forEventPage($competitionEvent, $isPubliclyVisible, $loggedPlayer?->playerId);

        $registration = $this->getCompetitionRegistrationOverview->forCompetition(
            $competitionId,
            $loggedPlayer?->playerId,
        );
        $now = $this->clock->now();

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
            'attendance' => $this->getEventAttendance->forPlayer($competitionId, $loggedPlayer?->playerId),
            'can_add_time' => $canAddTime,
            'event_offers' => $eventOffers,
            'event_offers_just_joined' => $eventOffers !== null && EventJustJoinedFlash::take($request, $competitionId),
            'registration' => $registration,
            'registration_is_open' => $registration->isOpen($now),
            'registration_opens_future' => $registration->opensInFuture($now),
            'published_results' => $this->getOfficialRoundResults->publishedStandingsForCompetition($competitionId),
            'page_sections' => $this->getCompetitionPageSections->forCompetition($competitionId),
            'rounds' => $rounds,
        ]);
    }
}
