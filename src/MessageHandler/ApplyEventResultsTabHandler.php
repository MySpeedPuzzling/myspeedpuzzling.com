<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use DateTimeZone;
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
use SpeedPuzzling\Web\Message\AddCompetition;
use SpeedPuzzling\Web\Message\AddCompetitionRound;
use SpeedPuzzling\Web\Message\ApplyEventResultsTab;
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
use SpeedPuzzling\Web\Services\PuzzlingTeamResolver;
use SpeedPuzzling\Web\Value\EventResultsImport\EventTabProblem;
use SpeedPuzzling\Web\Value\EventResultsImport\EventTabProblemCode;
use SpeedPuzzling\Web\Value\EventResultsImport\EventTabStatus;
use SpeedPuzzling\Web\Value\EventResultsImport\ParsedEventRow;
use SpeedPuzzling\Web\Value\EventResultsImport\ParsedEventTab;
use SpeedPuzzling\Web\Value\PuzzlersGroup;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\SolvingTimeDeletedVia;
use SpeedPuzzling\Web\Value\TeamComposition;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * One tab of an organizer's results sheet as an upsert (docs/features/event-results-import/README.md): the event and
 * its round through the same messages as the web forms, one nobody's result per team keyed by the team's folded name.
 * Nothing is imported without its puzzle; a tab with a problem changes nothing; a team somebody claimed is never
 * changed or removed by the import - a person decides.
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
        private PuzzlingTeamResolver $puzzlingTeamResolver,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ApplyEventResultsTab $message): void
    {
        $parsed = $message->tab;
        $sheet = $this->sheetRepository->get($message->sheetId);
        $tab = $this->tabRepository->findOfSheet($sheet, $message->gid);

        // Nothing changed since it was imported - a tab still waiting for its puzzle is looked at again every run
        if ($tab !== null && $tab->status === EventTabStatus::Imported && $tab->competition !== null && $tab->contentHash === $parsed->contentHash) {
            return;
        }

        $problems = $parsed->problems;
        $puzzle = null;
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
        }

        $competition = null;

        if ($puzzle !== null) {
            // First: the event's messages clear the entity manager (SecretPuzzleHides locks the round's puzzles), so
            // everything of this handler is read after them. They run in this handler's transaction - a failure later
            // takes the new event back too
            $createdCompetitionId = $tab?->competition === null ? $this->createEvent($sheet, $parsed, $puzzle->id->toString()) : null;

            $sheet = $this->sheetRepository->get($message->sheetId);
            $tab = $this->tabRepository->findOfSheet($sheet, $message->gid) ?? $this->newTab($sheet, $message);
            $puzzle = $this->puzzle($puzzle->id->toString());
            assert($puzzle !== null);

            $competition = $createdCompetitionId !== null
                ? $this->competitionRepository->get($createdCompetitionId)
                : $tab->competition;
            assert($competition !== null);

            $status = $this->apply($sheet, $tab, $parsed, $competition, $puzzle, $problems);
        }

        $tab ??= $this->newTab($sheet, $message);
        // Every way through the import above decided it
        assert($status !== null);

        $changed = $tab->record($message->title, $status, $problems, $parsed->contentHash, $competition, $this->clock->now());

        if ($changed && ($status !== EventTabStatus::Imported || $problems !== [])) {
            // Once per change, not every hourly run - the organizer fixes the sheet, somebody tells them what
            $this->logger->warning('Event results tab "{title}" of sheet {sheet}: {status}', [
                'title' => $message->title,
                'sheet' => $sheet->id->toString(),
                'status' => $status->value,
                'problems' => array_map(static fn (EventTabProblem $problem): array => $problem->toArray(), $problems),
            ]);
        }
    }

    /**
     * @param list<EventTabProblem> $problems
     */
    private function apply(
        EventResultsSheet $sheet,
        EventResultsSheetTab $tab,
        ParsedEventTab $parsed,
        Competition $competition,
        Puzzle $puzzle,
        array &$problems,
    ): EventTabStatus {
        // Checked by hasTabProblem() - every event field is there
        assert($parsed->eventName !== null && $parsed->location !== null && $parsed->cityAndState !== null);
        assert($parsed->localStartsAt !== null && $parsed->timeLimitMinutes !== null && $parsed->category !== null);

        $round = $this->roundRepository->ofCompetition($competition)[0] ?? null;

        if ($round === null || $round->category !== $parsed->category) {
            $problems[] = new EventTabProblem(EventTabProblemCode::CompetitionTypeChangedAfterImport, $parsed->category->value);

            return EventTabStatus::Problem;
        }

        if ($this->roundPuzzleRepository->puzzleIdsOfRound($round) !== [$puzzle->id->toString()]) {
            $problems[] = new EventTabProblem(EventTabProblemCode::PuzzleChangedAfterImport, $puzzle->id->toString());

            return EventTabStatus::Problem;
        }

        [$location, $eventDay, $startsAt] = self::eventPlaceAndTime($sheet, $parsed);
        $this->updateEvent($competition, $round, $sheet, $parsed, $location, $eventDay, $startsAt);
        $this->upsertResults($tab, $parsed, $competition, $round, $puzzle, $eventDay, $problems);

        return EventTabStatus::Imported;
    }

    private function newTab(EventResultsSheet $sheet, ApplyEventResultsTab $message): EventResultsSheetTab
    {
        $tab = new EventResultsSheetTab(Uuid::uuid7(), $sheet, $message->gid, $message->title);
        $this->tabRepository->save($tab);

        return $tab;
    }

    /**
     * @return array{string, DateTimeImmutable, DateTimeImmutable} location, the event's day, the round's start (UTC)
     */
    private static function eventPlaceAndTime(EventResultsSheet $sheet, ParsedEventTab $parsed): array
    {
        assert($parsed->location !== null && $parsed->cityAndState !== null && $parsed->localStartsAt !== null);

        $startsAt = new DateTimeImmutable($parsed->localStartsAt->format('Y-m-d H:i'), new DateTimeZone($sheet->timezone))
            ->setTimezone(new DateTimeZone('UTC'));

        // A date like the add form's: the event's own day, never shifted by a time zone
        $eventDay = new DateTimeImmutable($parsed->localStartsAt->format('Y-m-d'));

        return [$parsed->location . ', ' . $parsed->cityAndState, $eventDay, $startsAt];
    }

    /**
     * @return string the new event's id
     */
    private function createEvent(EventResultsSheet $sheet, ParsedEventTab $parsed, string $puzzleId): string
    {
        assert($parsed->eventName !== null && $parsed->timeLimitMinutes !== null && $parsed->category !== null);
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
        ));

        $this->messageBus->dispatch(new SetCompetitionRoundPuzzles($roundId->toString(), [$puzzleId]));

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
        ParsedEventTab $parsed,
        string $location,
        DateTimeImmutable $eventDay,
        DateTimeImmutable $startsAt,
    ): void {
        assert($parsed->eventName !== null && $parsed->timeLimitMinutes !== null && $parsed->category !== null);

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

        if ($round->minutesLimit !== $parsed->timeLimitMinutes || $round->startsAt->getTimestamp() !== $startsAt->getTimestamp()) {
            $round->edit(
                name: $round->name,
                minutesLimit: $parsed->timeLimitMinutes,
                startsAt: $startsAt,
                timezone: $sheet->timezone,
                badgeBackgroundColor: $round->badgeBackgroundColor,
                badgeTextColor: $round->badgeTextColor,
                category: $round->category,
                resultsLink: $round->resultsLink,
            );
        }

        $teamSize = self::teamSize($parsed);

        if ($round->teamSize !== $teamSize && $round->category === RoundCategory::Team) {
            $round->changeTeamSize($teamSize);
        }
    }

    /**
     * @param list<EventTabProblem> $problems
     */
    private function upsertResults(
        EventResultsSheetTab $tab,
        ParsedEventTab $parsed,
        Competition $competition,
        CompetitionRound $round,
        Puzzle $puzzle,
        DateTimeImmutable $eventDay,
        array &$problems,
    ): void {
        $existing = $this->importedResultRepository->ofTab($tab);

        foreach ($parsed->rows as $row) {
            $imported = $existing[$row->teamKey] ?? null;
            unset($existing[$row->teamKey]);

            if ($imported === null) {
                $this->createResult($tab, $row, $competition, $round, $puzzle, $eventDay);
                continue;
            }

            $this->updateResult($imported->solvingTime, $row, $problems);
        }

        // Taken off the sheet
        foreach ($existing as $imported) {
            $time = $imported->solvingTime;

            if ($time->isClaimedByAnybody()) {
                $problems[] = new EventTabProblem(EventTabProblemCode::ClaimedTeamRemovedFromSheet, $time->puzzlingTeam?->name);
                continue;
            }

            $this->importedResultRepository->delete($imported);
            $this->solvingTimeRepository->delete($time, SolvingTimeDeletedVia::EventImport);
        }
    }

    private function createResult(
        EventResultsSheetTab $tab,
        ParsedEventRow $row,
        Competition $competition,
        CompetitionRound $round,
        Puzzle $puzzle,
        DateTimeImmutable $eventDay,
    ): void {
        [$group, $puzzlingTeam] = $this->anonymousTeam($row);

        $time = PuzzleSolvingTime::importedFromEvent(
            id: Uuid::uuid7(),
            puzzle: $puzzle,
            competition: $competition,
            competitionRound: $round,
            anonymousGroup: $group,
            puzzlingTeam: $puzzlingTeam,
            secondsToSolve: $row->seconds,
            solvedAt: $eventDay,
        );

        $this->solvingTimeRepository->save($time);
        $this->importedResultRepository->save(new EventImportedResult(Uuid::uuid7(), $tab, $row->teamKey, $time));
    }

    /**
     * @param list<EventTabProblem> $problems
     */
    private function updateResult(PuzzleSolvingTime $time, ParsedEventRow $row, array &$problems): void
    {
        $time->correctImportedTime($row->seconds);

        if ($time->isClaimedByAnybody()) {
            if ($time->puzzlersCount !== $row->teamSize) {
                $problems[] = new EventTabProblem(EventTabProblemCode::ClaimedTeamChangedSize, $row->teamName, $row->line);
            }

            return;
        }

        if ($time->puzzlersCount !== $row->teamSize) {
            [$group, $puzzlingTeam] = $this->anonymousTeam($row);
            $time->replaceAnonymousGroup($group, $puzzlingTeam);

            return;
        }

        // The same team written another way ("puzzle pals" -> "Puzzle Pals") - the shown name follows the sheet
        if ($time->puzzlingTeam !== null && $time->puzzlingTeam->name !== $row->teamName) {
            $time->puzzlingTeam->nameFromEvent($row->teamName);
        }
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
     * The size most teams had - a team of three at a teams event of fours is normal.
     */
    private static function teamSize(ParsedEventTab $parsed): null|int
    {
        if ($parsed->category !== RoundCategory::Team || $parsed->rows === []) {
            return null;
        }

        $counts = array_count_values(array_map(static fn (ParsedEventRow $row): int => $row->teamSize, $parsed->rows));
        arsort($counts);

        $size = (int) array_key_first($counts);

        return max(CompetitionRound::TEAM_SIZE_MIN, min(CompetitionRound::TEAM_SIZE_MAX, $size));
    }
}
