<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\EventImportedResult;
use SpeedPuzzling\Web\Entity\EventResultsSheet;
use SpeedPuzzling\Web\Entity\EventResultsSheetTab;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\RelayResult;
use SpeedPuzzling\Web\Exceptions\InvalidPiecesPlaced;
use SpeedPuzzling\Web\Exceptions\UnfinishedResultNeedsTimeLimit;
use SpeedPuzzling\Web\Exceptions\UnfinishedTotalTimeWithinLimit;
use SpeedPuzzling\Web\Message\AddCompetition;
use SpeedPuzzling\Web\Message\AddCompetitionRound;
use SpeedPuzzling\Web\Message\ApplyEventResultsTab;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Message\RecalculateXpChainForSolve;
use SpeedPuzzling\Web\Message\SetCompetitionRoundPuzzles;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundPuzzleRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\EventImportedResultRepository;
use SpeedPuzzling\Web\Repository\EventResultsSheetRepository;
use SpeedPuzzling\Web\Repository\EventResultsSheetTabRepository;
use SpeedPuzzling\Web\Repository\PuzzleRedirectRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\RelayResultRepository;
use SpeedPuzzling\Web\Services\PuzzlingTeamResolver;
use SpeedPuzzling\Web\Value\EventResultsImport\AppliedEventResultsTab;
use SpeedPuzzling\Web\Value\EventResultsImport\EventTabProblem;
use SpeedPuzzling\Web\Value\EventResultsImport\EventTabProblemCode;
use SpeedPuzzling\Web\Value\EventResultsImport\EventTabStatus;
use SpeedPuzzling\Web\Value\EventResultsImport\ParsedEventRow;
use SpeedPuzzling\Web\Value\EventResultsImport\ParsedEvent;
use SpeedPuzzling\Web\Value\PuzzlersGroup;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\SolvingTimeDeletedVia;
use SpeedPuzzling\Web\Value\TeamComposition;
use SpeedPuzzling\Web\Value\UnfinishedResult;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * One tab of an organizer's results sheet as an upsert (docs/features/event-results-import/README.md): the event and
 * its round through the same messages as the web forms, one nobody's result per team keyed by the team's folded name.
 * Nothing is imported without its puzzle; a tab with a problem changes nothing; a team somebody claimed is never
 * changed or removed by the import - a person decides; a team gone from the sheet is removed when nobody claimed it
 * (P4 of results-import-page.md). An event read before it was last applied (an import from an older check) is refused.
 * Answers what it did (AppliedEventResultsTab).
 */
#[AsMessageHandler]
readonly final class ApplyEventResultsTabHandler
{
    public function __construct(
        private EventResultsSheetRepository $sheetRepository,
        private EventResultsSheetTabRepository $tabRepository,
        private EventImportedResultRepository $importedResultRepository,
        private CompetitionRepository $competitionRepository,
        private CompetitionRoundRepository $roundRepository,
        private CompetitionRoundPuzzleRepository $roundPuzzleRepository,
        private PuzzleRepository $puzzleRepository,
        private PuzzleRedirectRepository $puzzleRedirectRepository,
        private PuzzleSolvingTimeRepository $solvingTimeRepository,
        private RelayResultRepository $relayResultRepository,
        private PuzzlingTeamResolver $puzzlingTeamResolver,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ApplyEventResultsTab $message): AppliedEventResultsTab
    {
        $parsed = $message->event;
        $sheet = $this->sheetRepository->get($message->sheetId);
        $tab = $this->tabOf($sheet, $message);

        // Nothing changed since it was imported - a tab still waiting for its puzzle is looked at again every run
        if ($tab !== null && $tab->status === EventTabStatus::Imported && $tab->competition !== null && $tab->contentHash === $parsed->contentHash) {
            return new AppliedEventResultsTab(EventTabStatus::Imported, $tab->competition->id->toString(), unchanged: true);
        }

        // Applied after this read was made (somebody imported a newer check meanwhile) - what they published stays
        if ($message->readAt !== null && $tab?->appliedAt !== null && $tab->appliedAt > $message->readAt) {
            return AppliedEventResultsTab::stale($tab->competition?->id->toString());
        }

        $problems = $parsed->problems;
        $puzzle = null;
        // A relay's second puzzle (solo-and-relays.md §2) - null for every other tab
        $secondPuzzle = null;
        $status = match (true) {
            $tab?->isDetached() === true => EventTabStatus::Detached,
            $parsed->hasTabProblem() => EventTabStatus::Problem,
            $parsed->puzzleId === null => EventTabStatus::WaitingForPuzzle,
            default => null,
        };

        if ($status === null) {
            $puzzle = $this->puzzle($parsed->puzzleId);

            if ($puzzle === null) {
                $problems[] = new EventTabProblem(EventTabProblemCode::PuzzleNotFound, $parsed->puzzleId);
                $status = EventTabStatus::WaitingForPuzzle;
            }

            // A relay waits for both its puzzles - the missing one named
            if ($parsed->secondPuzzleId !== null) {
                $secondPuzzle = $this->puzzle($parsed->secondPuzzleId);

                if ($secondPuzzle === null) {
                    $problems[] = new EventTabProblem(EventTabProblemCode::PuzzleNotFound, $parsed->secondPuzzleId);
                    $puzzle = null;
                    $status = EventTabStatus::WaitingForPuzzle;
                } elseif ($puzzle !== null && $puzzle->id->equals($secondPuzzle->id)) {
                    // Two links of one puzzle - one of them merged into the other meanwhile
                    $problems[] = new EventTabProblem(EventTabProblemCode::RelaySamePuzzleTwice, $secondPuzzle->id->toString());
                    $puzzle = null;
                    $status = EventTabStatus::Problem;
                }
            }
        }

        $competition = null;
        $counts = [0, 0, 0];

        // Unfinished teams: their pieces placed checked against the puzzle before anything is created or changed - a
        // new tab must not leave an event behind, an imported one must not change half its results
        $unfinished = [];

        if ($puzzle !== null) {
            $unfinished = $this->unfinishedResults($parsed, $puzzle, $problems);

            if ($unfinished === null) {
                $puzzle = null;
                $status = EventTabStatus::Problem;
            }
        }

        if ($puzzle !== null) {
            // First: the event's messages clear the entity manager (SecretPuzzleHides locks the round's puzzles), so
            // everything of this handler is read after them. They run in this handler's transaction - a failure later
            // takes the new event back too
            $puzzleIds = [$puzzle->id->toString()];

            if ($secondPuzzle !== null) {
                $puzzleIds[] = $secondPuzzle->id->toString();
            }

            $createdCompetitionId = $tab?->competition === null ? $this->createEvent($sheet, $parsed, $puzzleIds) : null;

            $sheet = $this->sheetRepository->get($message->sheetId);
            $tab = $this->tabOf($sheet, $message) ?? $this->newTab($sheet, $message);
            $puzzle = $this->puzzle($puzzle->id->toString());
            assert($puzzle !== null);
            $secondPuzzle = $secondPuzzle !== null ? $this->puzzle($secondPuzzle->id->toString()) : null;

            $competition = $createdCompetitionId !== null
                ? $this->competitionRepository->get($createdCompetitionId)
                : $tab->competition;
            assert($competition !== null);

            $status = $this->apply($sheet, $tab, $parsed, $competition, $secondPuzzle === null ? [$puzzle] : [$puzzle, $secondPuzzle], $unfinished, $problems, $counts);
        }

        $tab ??= $this->newTab($sheet, $message);
        // Every way through the import above decided it
        assert($status !== null);

        $changed = $tab->record($parsed->title, $status, $problems, $parsed->contentHash, $competition, $this->clock->now());

        if ($changed && ($status !== EventTabStatus::Imported || $problems !== [])) {
            // Once per change, not every hourly run - the organizer fixes the sheet, somebody tells them what
            $this->logger->warning('Event results tab "{title}" of sheet {sheet}: {status}', [
                'title' => $parsed->title,
                'sheet' => $sheet->id->toString(),
                'status' => $status->value,
                'problems' => array_map(static fn (EventTabProblem $problem): array => $problem->toArray(), $problems),
            ]);
        }

        return new AppliedEventResultsTab(
            status: $status,
            competitionId: $tab->competition?->id->toString(),
            teamsAdded: $counts[0],
            teamsUpdated: $counts[1],
            teamsRemoved: $counts[2],
        );
    }

    /**
     * Every unfinished team's result by its team key (docs/features/event-results-import/README.md "Unfinished results
     * (D9)"): pieces placed fewer than the puzzle has, time spent = the round's limit. Null when a team's pieces do not
     * fit the puzzle - the tab is a problem then and nothing of it is applied.
     *
     * @param list<EventTabProblem> $problems
     * @return null|array<string, UnfinishedResult>
     */
    private function unfinishedResults(ParsedEvent $parsed, Puzzle $puzzle, array &$problems): null|array
    {
        $unfinished = [];
        $fits = true;

        foreach ($parsed->rows as $row) {
            if ($row->piecesPlaced === null) {
                continue;
            }

            try {
                $unfinished[$row->teamKey] = UnfinishedResult::fromInput($row->piecesPlaced, null, $puzzle->piecesCount, $parsed->timeLimitMinutes);
            } catch (InvalidPiecesPlaced) {
                $problems[] = new EventTabProblem(EventTabProblemCode::PiecesPlacedOutOfRange, sprintf('%s: %d / %d', $row->teamName, $row->piecesPlaced, $puzzle->piecesCount), $row->line);
                $fits = false;
            } catch (UnfinishedResultNeedsTimeLimit | UnfinishedTotalTimeWithinLimit) {
                // The parser keeps a tab with unfinished teams and no limit out already, and an import has no total
                $problems[] = new EventTabProblem(EventTabProblemCode::UnfinishedWithoutTimeLimit, $row->teamName, $row->line);
                $fits = false;
            }
        }

        return $fits ? $unfinished : null;
    }

    /**
     * @param non-empty-list<Puzzle> $puzzles the event's puzzle - or a relay's two (solo-and-relays.md §2)
     * @param array<string, UnfinishedResult> $unfinished
     * @param list<EventTabProblem> $problems
     * @param array{int, int, int} $counts teams added, updated, removed
     */
    private function apply(
        EventResultsSheet $sheet,
        EventResultsSheetTab $tab,
        ParsedEvent $parsed,
        Competition $competition,
        array $puzzles,
        array $unfinished,
        array &$problems,
        array &$counts,
    ): EventTabStatus {
        // Checked by hasTabProblem() - every event field is there
        assert($parsed->eventName !== null && $parsed->location !== null && $parsed->cityAndState !== null);
        assert($parsed->localStartsAt !== null && $parsed->category !== null);

        $round = $this->roundRepository->ofCompetition($competition)[0] ?? null;

        if ($round === null || $round->category !== $parsed->category) {
            $problems[] = new EventTabProblem(EventTabProblemCode::CompetitionTypeChangedAfterImport, $parsed->category->value);

            return EventTabStatus::Problem;
        }

        // The round's puzzles are the tab's - a tab that turned into a relay or back, or got another puzzle, is for a person
        $puzzleIds = array_map(static fn (Puzzle $puzzle): string => $puzzle->id->toString(), $puzzles);
        $sortedPuzzleIds = $puzzleIds;
        $roundPuzzleIds = $this->roundPuzzleRepository->puzzleIdsOfRound($round);
        sort($sortedPuzzleIds);
        sort($roundPuzzleIds);

        if ($roundPuzzleIds !== $sortedPuzzleIds) {
            $problems[] = new EventTabProblem(EventTabProblemCode::PuzzleChangedAfterImport, implode(', ', $puzzleIds));

            return EventTabStatus::Problem;
        }

        [$location, $eventDay, $startsAt] = self::eventPlaceAndTime($sheet, $parsed);
        $this->updateEvent($competition, $round, $sheet, $parsed, $location, $eventDay, $startsAt);
        $counts = $this->upsertResults($tab, $parsed, $competition, $round, $puzzles, $eventDay, $unfinished, $problems);

        return EventTabStatus::Imported;
    }

    /**
     * The stored item of the event - under its key, or (after the organizer changed the spreadsheet) the event imported
     * under the key it replaces, moved to the event's key. Asked twice per apply (the event's messages may clear the
     * entity manager): the move is not flushed yet, so the second lookup finds it under the old key again and moving
     * it once more changes nothing.
     */
    private function tabOf(EventResultsSheet $sheet, ApplyEventResultsTab $message): null|EventResultsSheetTab
    {
        $key = $message->event->key;
        $tab = $this->tabRepository->findOfSheet($sheet, $key);

        if ($message->replacesKey === null || $message->replacesKey === $key || $tab?->competition !== null) {
            return $tab;
        }

        $previous = $this->tabRepository->findOfSheet($sheet, $message->replacesKey);

        // Only an imported event moves - nothing else of the previous sheet is worth keeping
        if ($previous === null || $previous->competition === null) {
            return $tab;
        }

        if ($tab !== null) {
            // The importer removes a never-imported item under the key first (RemoveUnimportedResultsSourceItem) - two
            // items under one key would break the unique key, and applying it as new would make the event twice
            throw new LogicException(sprintf('Event "%s" of results source %s: an item is stored under its key already, it cannot replace "%s".', $key, $sheet->id->toString(), $message->replacesKey));
        }

        if ($previous->gid !== $key) {
            $this->logger->info('Event "{title}" of results source {sheet} moves from key {from} to {to} (matched in the new spreadsheet by date and name)', [
                'title' => $message->event->title,
                'sheet' => $sheet->id->toString(),
                'from' => $previous->gid,
                'to' => $key,
            ]);
            $previous->movedToKey($key);
        }

        return $previous;
    }

    private function newTab(EventResultsSheet $sheet, ApplyEventResultsTab $message): EventResultsSheetTab
    {
        $tab = new EventResultsSheetTab(Uuid::uuid7(), $sheet, $message->event->key, $message->event->title);
        $this->tabRepository->save($tab);

        return $tab;
    }

    /**
     * @return array{string, DateTimeImmutable, DateTimeImmutable} location, the event's day, the round's start (UTC)
     */
    private static function eventPlaceAndTime(EventResultsSheet $sheet, ParsedEvent $parsed): array
    {
        assert($parsed->location !== null && $parsed->cityAndState !== null && $parsed->localStartsAt !== null);

        $startsAt = new DateTimeImmutable($parsed->localStartsAt->format('Y-m-d H:i'), new DateTimeZone($sheet->timezone))
            ->setTimezone(new DateTimeZone('UTC'));

        // A date like the add form's: the event's own day, never shifted by a time zone
        $eventDay = new DateTimeImmutable($parsed->localStartsAt->format('Y-m-d'));

        return [$parsed->location . ', ' . $parsed->cityAndState, $eventDay, $startsAt];
    }

    /**
     * @param non-empty-list<string> $puzzleIds the round's puzzles - a relay's two (solo-and-relays.md §2)
     * @return string the new event's id
     */
    private function createEvent(EventResultsSheet $sheet, ParsedEvent $parsed, array $puzzleIds): string
    {
        assert($parsed->eventName !== null && $parsed->category !== null);
        [$location, $eventDay, $startsAt] = self::eventPlaceAndTime($sheet, $parsed);

        $competitionId = Uuid::uuid7();
        $roundId = Uuid::uuid7();

        // The web forms' messages: slug, approval (OrganizationApprovalPolicy) and checks as for anybody on the team
        $this->messageBus->dispatch(new AddCompetition(
            competitionId: $competitionId,
            playerId: $sheet->actingPlayer->id->toString(),
            name: $parsed->eventName,
            shortcut: null,
            description: null,
            link: null,
            registrationLink: null,
            resultsLink: null,
            location: $location,
            locationCountryCode: $sheet->countryCode,
            dateFrom: $eventDay,
            dateTo: $eventDay,
            isOnline: false,
            logo: null,
            maintainerIds: [],
            notifyAdmin: false,
            organizationId: $sheet->organization->id->toString(),
        ));

        $this->messageBus->dispatch(new AddCompetitionRound(
            roundId: $roundId,
            competitionId: $competitionId->toString(),
            name: self::roundName($parsed->category),
            minutesLimit: $parsed->timeLimitMinutes,
            startsAt: $startsAt,
            timezone: $sheet->timezone,
            badgeBackgroundColor: null,
            badgeTextColor: null,
            category: $parsed->category,
            teamSize: self::teamSize($parsed),
            startTimeKnown: $parsed->startTimeKnown,
        ));

        $this->messageBus->dispatch(new SetCompetitionRoundPuzzles($roundId->toString(), $puzzleIds));

        return $competitionId->toString();
    }

    /**
     * Only what the sheet says - everything else on the event (description, logo, team, links) is the organizer's on
     * MySpeedPuzzling and stays as it is. A rename keeps the address.
     */
    private function updateEvent(
        Competition $competition,
        CompetitionRound $round,
        EventResultsSheet $sheet,
        ParsedEvent $parsed,
        string $location,
        DateTimeImmutable $eventDay,
        DateTimeImmutable $startsAt,
    ): void {
        assert($parsed->eventName !== null && $parsed->category !== null);

        if (
            $competition->name !== $parsed->eventName
            || $competition->location !== $location
            || $competition->dateFrom?->format('Y-m-d') !== $eventDay->format('Y-m-d')
        ) {
            $competition->edit(
                name: $parsed->eventName,
                slug: $competition->slug,
                shortcut: $competition->shortcut,
                logo: $competition->logo,
                description: $competition->description,
                link: $competition->link,
                registrationLink: $competition->registrationLink,
                resultsLink: $competition->resultsLink,
                location: $location,
                locationCountryCode: $competition->locationCountryCode,
                dateFrom: $eventDay,
                dateTo: $eventDay,
                isOnline: false,
            );
        }

        if (
            $round->minutesLimit !== $parsed->timeLimitMinutes
            || $round->startsAt->getTimestamp() !== $startsAt->getTimestamp()
            || $round->startTimeKnown !== $parsed->startTimeKnown
        ) {
            $round->edit(
                name: $round->name,
                minutesLimit: $parsed->timeLimitMinutes,
                startsAt: $startsAt,
                timezone: $sheet->timezone,
                badgeBackgroundColor: $round->badgeBackgroundColor,
                badgeTextColor: $round->badgeTextColor,
                category: $round->category,
                resultsLink: $round->resultsLink,
                startTimeKnown: $parsed->startTimeKnown,
            );
        }

        $teamSize = self::teamSize($parsed);

        if ($round->teamSize !== $teamSize && $round->category === RoundCategory::Team) {
            $round->changeTeamSize($teamSize);
        }
    }

    /**
     * @param non-empty-list<Puzzle> $puzzles
     * @param array<string, UnfinishedResult> $unfinished
     * @param list<EventTabProblem> $problems
     * @return array{int, int, int} teams added, updated, removed
     */
    private function upsertResults(
        EventResultsSheetTab $tab,
        ParsedEvent $parsed,
        Competition $competition,
        CompetitionRound $round,
        array $puzzles,
        DateTimeImmutable $eventDay,
        array $unfinished,
        array &$problems,
    ): array {
        $existing = $this->importedResultRepository->ofTab($tab);
        [$added, $updated, $removed] = [0, 0, 0];

        foreach ($parsed->rows as $row) {
            $imported = $existing[$row->teamKey] ?? null;
            unset($existing[$row->teamKey]);

            if ($imported === null) {
                if (count($puzzles) > 1) {
                    $this->createRelayResult($tab, $row, $competition, $round, $puzzles, $eventDay);
                } else {
                    $this->createResult($tab, $row, $competition, $round, $puzzles[0], $eventDay, $unfinished[$row->teamKey] ?? null);
                }

                $added++;
                continue;
            }

            // The round's puzzles are the tab's (checked in apply()), so a team's rows are a relay's exactly on a relay tab
            $changed = count($puzzles) > 1
                ? $this->updateRelayResult($imported, $row, $problems)
                : $this->updateResult($imported[0], $row, $unfinished[$row->teamKey] ?? null, $problems);

            if ($changed) {
                $updated++;
            }
        }

        // Taken off the sheet: removed when nobody claimed it, kept when somebody did (P4) - a person decides. A relay
        // team goes whole: both its results, then the relay
        foreach ($existing as $legs) {
            $time = $legs[0]->solvingTime;

            if ($time->isClaimedByAnybody()) {
                $problems[] = new EventTabProblem(EventTabProblemCode::ClaimedTeamRemovedFromSheet, $time->puzzlingTeam->name ?? $legs[0]->name);
                continue;
            }

            $relayResult = $time->relayResult;

            foreach ($legs as $imported) {
                $this->importedResultRepository->delete($imported);
                $this->solvingTimeRepository->delete($imported->solvingTime, SolvingTimeDeletedVia::EventImport);
            }

            if ($relayResult !== null) {
                $this->relayResultRepository->delete($relayResult);
            }

            $removed++;
        }

        return [$added, $updated, $removed];
    }

    /**
     * A relay team (solo-and-relays.md §2): one result per puzzle, linked by one RelayResult holding the total, both
     * with the same anonymous people (one shared pair/team) - or a single person's - and an equal share of the total
     * as time spent (the remainder on the first puzzle).
     *
     * @param non-empty-list<Puzzle> $puzzles
     */
    private function createRelayResult(
        EventResultsSheetTab $tab,
        ParsedEventRow $row,
        Competition $competition,
        CompetitionRound $round,
        array $puzzles,
        DateTimeImmutable $eventDay,
    ): void {
        // The parser keeps a relay tab with an unfinished team out (relay_needs_time)
        assert($row->seconds !== null);

        $relayResult = new RelayResult(Uuid::uuid7(), $row->seconds, $this->clock->now());
        $this->relayResultRepository->save($relayResult);

        [$group, $puzzlingTeam] = $row->teamSize === 1 ? [null, null] : $this->anonymousTeam($row);
        $shares = RelayResult::shares($row->seconds, count($puzzles));

        foreach ($puzzles as $leg => $puzzle) {
            $time = PuzzleSolvingTime::importedRelayFromEvent(
                id: Uuid::uuid7(),
                puzzle: $puzzle,
                competition: $competition,
                competitionRound: $round,
                anonymousGroup: $group,
                puzzlingTeam: $puzzlingTeam,
                relayResult: $relayResult,
                secondsSpent: $shares[$leg],
                solvedAt: $eventDay,
            );

            $this->solvingTimeRepository->save($time);
            $this->importedResultRepository->save(new EventImportedResult(Uuid::uuid7(), $tab, $row->teamKey, $time, $row->teamName, $leg));
        }
    }

    /**
     * A relay team follows the sheet like any team (updateResult()): its total - each puzzle's share with it - and,
     * while nobody claimed it, its size and name; its results always together.
     *
     * @param non-empty-list<EventImportedResult> $legs
     * @param list<EventTabProblem> $problems
     * @return bool whether anything of the result changed
     */
    private function updateRelayResult(array $legs, ParsedEventRow $row, array &$problems): bool
    {
        assert($row->seconds !== null);

        $times = array_map(static fn (EventImportedResult $imported): PuzzleSolvingTime => $imported->solvingTime, $legs);
        $first = $times[0];
        $relayResult = $first->relayResult;
        assert($relayResult !== null);

        // Shown only for a single person nobody has claimed (updateResult())
        $nameChanged = false;

        foreach ($legs as $imported) {
            $nameChanged = ($imported->nameFromSheet($row->teamName) && $imported->solvingTime->team === null && $imported->solvingTime->player === null) || $nameChanged;
        }

        $changed = $relayResult->correctTotal($row->seconds);
        $shares = RelayResult::shares($row->seconds, count($times));

        foreach ($times as $leg => $time) {
            $changed = $time->correctImportedRelayShare($shares[$leg]) || $changed;
        }

        if ($first->isClaimedByAnybody()) {
            if ($changed) {
                $memberPlayerIds = $first->memberPlayerIds();

                foreach ($memberPlayerIds as $memberPlayerId) {
                    $this->messageBus->dispatch(new RecalculateBadgesForPlayer($memberPlayerId));
                }

                foreach ($times as $time) {
                    $this->messageBus->dispatch(new RecalculateXpChainForSolve($time->id->toString(), $memberPlayerIds));
                }
            }

            if ($first->puzzlersCount !== $row->teamSize) {
                $problems[] = new EventTabProblem(EventTabProblemCode::ClaimedTeamChangedSize, $row->teamName, $row->line);
            }

            return $changed;
        }

        if ($first->puzzlersCount !== $row->teamSize) {
            if ($row->teamSize === 1) {
                foreach ($times as $time) {
                    $time->becomeAnonymousSolo();
                }

                return true;
            }

            // One new anonymous team for both results - they stay the same people
            [$group, $puzzlingTeam] = $this->anonymousTeam($row);

            foreach ($times as $time) {
                $time->replaceAnonymousGroup($group, $puzzlingTeam);
            }

            return true;
        }

        // Both results share the pair/team - renamed once
        if ($first->puzzlingTeam !== null && $first->puzzlingTeam->name !== $row->teamName) {
            $first->puzzlingTeam->nameFromEvent($row->teamName);

            return true;
        }

        return $changed || $nameChanged;
    }

    private function createResult(
        EventResultsSheetTab $tab,
        ParsedEventRow $row,
        Competition $competition,
        CompetitionRound $round,
        Puzzle $puzzle,
        DateTimeImmutable $eventDay,
        null|UnfinishedResult $unfinished,
    ): void {
        if ($row->teamSize === 1) {
            // A single person: a solo result without its player, the sheet's name on the imported row
            // (solo-and-relays.md §1) - at a teams or pairs event too, in the tab's one round (D10)
            $time = PuzzleSolvingTime::importedSoloFromEvent(
                id: Uuid::uuid7(),
                puzzle: $puzzle,
                competition: $competition,
                competitionRound: $round,
                secondsToSolve: $unfinished === null ? $row->seconds : null,
                solvedAt: $eventDay,
                unfinished: $unfinished,
            );
        } else {
            [$group, $puzzlingTeam] = $this->anonymousTeam($row);

            $time = PuzzleSolvingTime::importedFromEvent(
                id: Uuid::uuid7(),
                puzzle: $puzzle,
                competition: $competition,
                competitionRound: $round,
                anonymousGroup: $group,
                puzzlingTeam: $puzzlingTeam,
                secondsToSolve: $unfinished === null ? $row->seconds : null,
                solvedAt: $eventDay,
                unfinished: $unfinished,
            );
        }

        $this->solvingTimeRepository->save($time);
        $this->importedResultRepository->save(new EventImportedResult(Uuid::uuid7(), $tab, $row->teamKey, $time, $row->teamName));
    }

    /**
     * The team's result follows the sheet - its time, or its pieces placed (and time spent, when the limit changed), also
     * from one to the other. Its people do not once somebody claimed a spot.
     *
     * @param list<EventTabProblem> $problems
     * @return bool whether anything of the result changed
     */
    private function updateResult(EventImportedResult $imported, ParsedEventRow $row, null|UnfinishedResult $unfinished, array &$problems): bool
    {
        $time = $imported->solvingTime;
        // The sheet's name of every row (solo-and-relays.md §1) - shown only for a single person nobody has claimed, so
        // only there a change of it is a change of the result (a team's row imported before the column just gets it)
        $nameChanged = $imported->nameFromSheet($row->teamName) && $time->team === null && $time->player === null;

        if ($unfinished !== null) {
            $changed = $time->correctImportedUnfinishedResult($unfinished);
        } else {
            assert($row->seconds !== null);
            $changed = $time->correctImportedTime($row->seconds);
        }

        if ($time->isClaimedByAnybody()) {
            // Claimed: the result is on people's profiles - their achievements and XP follow it (a team that turned
            // out unfinished earns nothing, ClaimEventResultSlotHandler's recalculation)
            if ($changed) {
                $memberPlayerIds = $time->memberPlayerIds();

                foreach ($memberPlayerIds as $memberPlayerId) {
                    $this->messageBus->dispatch(new RecalculateBadgesForPlayer($memberPlayerId));
                }

                $this->messageBus->dispatch(new RecalculateXpChainForSolve($time->id->toString(), $memberPlayerIds));
            }

            if ($time->puzzlersCount !== $row->teamSize) {
                $problems[] = new EventTabProblem(EventTabProblemCode::ClaimedTeamChangedSize, $row->teamName, $row->line);
            }

            return $changed;
        }

        if ($time->puzzlersCount !== $row->teamSize) {
            if ($row->teamSize === 1) {
                $time->becomeAnonymousSolo();

                return true;
            }

            [$group, $puzzlingTeam] = $this->anonymousTeam($row);
            $time->replaceAnonymousGroup($group, $puzzlingTeam);

            return true;
        }

        // The same team written another way ("puzzle pals" -> "Puzzle Pals") - the shown name follows the sheet
        if ($time->puzzlingTeam !== null && $time->puzzlingTeam->name !== $row->teamName) {
            $time->puzzlingTeam->nameFromEvent($row->teamName);

            return true;
        }

        return $changed || $nameChanged;
    }

    /**
     * @return array{PuzzlersGroup, \SpeedPuzzling\Web\Entity\PuzzlingTeam}
     */
    private function anonymousTeam(ParsedEventRow $row): array
    {
        $slots = [];

        for ($i = 0; $i < $row->teamSize; $i++) {
            $slots[] = TeamComposition::ANONYMOUS_PREFIX . Uuid::uuid7()->toString();
        }

        // ParsedEventRow::$teamSize is 1..20
        assert($slots !== []);
        $group = PuzzlersGroup::anonymous($slots);
        $puzzlingTeam = $this->puzzlingTeamResolver->resolve($group);
        assert($puzzlingTeam !== null);
        $puzzlingTeam->nameFromEvent($row->teamName);

        return [$group, $puzzlingTeam];
    }

    private function puzzle(string $puzzleId): null|Puzzle
    {
        $puzzle = $this->puzzleRepository->findById(Uuid::fromString($puzzleId));

        if ($puzzle !== null) {
            return $puzzle;
        }

        // Merged into another puzzle meanwhile - the survivor is the puzzle
        $redirect = $this->puzzleRedirectRepository->findByOldPuzzleId($puzzleId);

        return $redirect === null ? null : $this->puzzleRepository->findById($redirect->survivorPuzzleId);
    }

    private static function roundName(RoundCategory $category): string
    {
        return match ($category) {
            RoundCategory::Duo => 'Pairs',
            RoundCategory::Team => 'Teams',
            RoundCategory::Solo => 'Individual',
        };
    }

    /**
     * The size most teams had - a team of three at a teams event of fours is normal. Teams only: a pair at a teams event
     * (README D10) says nothing about the event's teams.
     */
    private static function teamSize(ParsedEvent $parsed): null|int
    {
        $teams = array_filter($parsed->rows, static fn (ParsedEventRow $row): bool => PuzzlingType::fromPuzzlersCount($row->teamSize) === PuzzlingType::Team);

        if ($parsed->category !== RoundCategory::Team || $teams === []) {
            return null;
        }

        $counts = array_count_values(array_map(static fn (ParsedEventRow $row): int => $row->teamSize, $teams));
        arsort($counts);

        $size = (int) array_key_first($counts);

        return max(CompetitionRound::TEAM_SIZE_MIN, min(CompetitionRound::TEAM_SIZE_MAX, $size));
    }
}
