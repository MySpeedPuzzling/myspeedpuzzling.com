<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Sandbox;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\CompetitorPage\CompetitorListController;
use SpeedPuzzling\Web\Exceptions\SandboxModeIsOff;
use SpeedPuzzling\Web\Message\AddCompetition;
use SpeedPuzzling\Web\Message\AddCompetitionDisplay;
use SpeedPuzzling\Web\Message\AddCompetitionReferee;
use SpeedPuzzling\Web\Message\AddCompetitionRound;
use SpeedPuzzling\Web\Message\ApproveCompetition;
use SpeedPuzzling\Web\Message\ArrangeRoundPuzzles;
use SpeedPuzzling\Web\Message\AssignTableNumbers;
use SpeedPuzzling\Web\Message\ChangeCompetitorPageSettings;
use SpeedPuzzling\Web\Message\ChangeRoundUnboxed;
use SpeedPuzzling\Web\Message\CopyRoundTableNumbers;
use SpeedPuzzling\Web\Message\CreateCompetitionStanding;
use SpeedPuzzling\Web\Message\EditCompetitionRound;
use SpeedPuzzling\Web\Message\ImportCompetitionParticipants;
use SpeedPuzzling\Web\Message\PublishCompetitionStanding;
use SpeedPuzzling\Web\Message\PublishRoundResults;
use SpeedPuzzling\Web\Message\RecordRoundResults;
use SpeedPuzzling\Web\Message\SaveCompetitionDisplayDesign;
use SpeedPuzzling\Web\Message\SetCompetitionDivisions;
use SpeedPuzzling\Web\Message\SetRoundEntryDivisions;
use SpeedPuzzling\Web\Message\SetCompetitionRoundPuzzles;
use SpeedPuzzling\Web\Query\GetCompetitionDivisions;
use SpeedPuzzling\Web\Query\GetRoundResultEntries;
use SpeedPuzzling\Web\Query\GetRoundResultsOverview;
use SpeedPuzzling\Web\Query\GetSandboxDemoData;
use SpeedPuzzling\Web\Query\GetSandboxSimulationData;
use SpeedPuzzling\Web\Results\ParticipantImportResult;
use SpeedPuzzling\Web\Results\RecordedRoundResults;
use SpeedPuzzling\Web\Results\RoundResultEntry;
use SpeedPuzzling\Web\Results\RoundResultChangeOutcome;
use SpeedPuzzling\Web\Services\CompetitionToolkit\DisplayPresets;
use SpeedPuzzling\Web\Services\SameTablesCopy;
use SpeedPuzzling\Web\Value\CompetitorPageSettings;
use SpeedPuzzling\Web\Value\DisplayKind;
use SpeedPuzzling\Web\Value\DivisionInput;
use SpeedPuzzling\Web\Value\EntryDivisionInput;
use SpeedPuzzling\Web\Value\DisplayPreset;
use SpeedPuzzling\Web\Value\QrTagMode;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundPuzzleFormat;
use SpeedPuzzling\Web\Value\RoundResultChangeStatus;
use SpeedPuzzling\Web\Value\SandboxDemoEvent;
use SpeedPuzzling\Web\Value\SandboxDemoGroup;
use SpeedPuzzling\Web\Value\SandboxDemoRound;
use SpeedPuzzling\Web\Value\StandingMissing;
use SpeedPuzzling\Web\Value\StandingOrder;
use SpeedPuzzling\Web\Value\StandingRules;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the sandbox's two demo events (docs/features/sandbox/README.md "Demo events", SandboxDemoEventDefinitions)
 * the way an organiser's tools would: every write is the message the organiser's page or the judge's device
 * dispatches - the event, its rounds and puzzles, the participants import, seating, standings, displays, official
 * results and their publishing - one message at a time, each in its own transaction. Reads go through queries only.
 *
 * Idempotent: everything is found again by the event's fixed slug and the rounds', standings' and displays' names;
 * what exists is kept (and only completed), recorded results are never overwritten. Sandbox only (SandboxMode) - it
 * refuses anywhere else before writing anything.
 */
final readonly class SandboxDemoEvents
{
    private const array FUN_FIRST = ['Corner', 'Edge', 'Swift', 'Lucky', 'Midnight', 'Maple', 'Northern', 'Frosty', 'Quiet', 'Golden', 'Pocket', 'Velvet'];
    private const array FUN_SECOND = ['Hunters', 'Crew', 'Sorters', 'Owls', 'Foxes', 'Builders', 'Comets', 'Pieces', 'Rascals', 'Mittens', 'Lynxes', 'Tabs'];

    private const string STANDING_NAME = '%s championship';

    public function __construct(
        private SandboxMode $sandboxMode,
        private MessageBusInterface $messageBus,
        private GetSandboxDemoData $demoData,
        private GetSandboxSimulationData $simulationData,
        private GetRoundResultsOverview $getRoundResultsOverview,
        private GetRoundResultEntries $getRoundResultEntries,
        private GetCompetitionDivisions $getCompetitionDivisions,
        private SameTablesCopy $sameTablesCopy,
        private ClockInterface $clock,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param null|string $date the demo day (Y-m-d) - null = today in each event's zone
     * @param \Closure(string): void $say one line of progress for the console
     *
     * @throws SandboxModeIsOff
     */
    public function setUp(null|string $date, string $organizerPlayerId, null|string $refereePlayerId, \Closure $say): void
    {
        $this->sandboxMode->assertOn();

        $people = array_values(array_filter(
            $this->simulationData->demoPeople(),
            static fn (array $person): bool => $person['id'] !== $organizerPlayerId && $person['id'] !== $refereePlayerId,
        ));

        foreach (SandboxDemoEventDefinitions::all() as $event) {
            $day = $date ?? $this->clock->now()->setTimezone(new DateTimeZone($event->timezone))->format('Y-m-d');
            $say('');
            $say(sprintf('== %s (demo day %s, %s)', $event->name, $day, $event->timezone));
            $this->setUpEvent($event, $day, $organizerPlayerId, $refereePlayerId, $people, $say);
        }
    }

    /**
     * @param list<array{id: string, name: string, country: null|string, email: string}> $people
     * @param \Closure(string): void $say
     */
    private function setUpEvent(SandboxDemoEvent $event, string $day, string $organizerPlayerId, null|string $refereePlayerId, array $people, \Closure $say): void
    {
        $competitionId = $this->competition($event, $day, $organizerPlayerId, $say);

        $this->messageBus->dispatch(new ChangeCompetitorPageSettings($competitionId, new CompetitorPageSettings(
            competitorPages: true,
            liveResultsBeforePublishing: true,
            timeProposals: true,
            qrTagMode: QrTagMode::Entrant,
        )));

        if ($refereePlayerId !== null) {
            $this->messageBus->dispatch(new AddCompetitionReferee($competitionId, $refereePlayerId, $organizerPlayerId));
        }

        $roundIds = $this->rounds($event, $competitionId, $day, $say);
        $this->puzzles($event, $competitionId, $day, $roundIds, $say);
        $this->participants($event, $competitionId, $people, $say);
        $this->divisions($event, $competitionId, $roundIds, $people, $say);
        $this->tables($event, $competitionId, $roundIds, $say);
        $this->results($event, $competitionId, $roundIds, $organizerPlayerId, $say);

        if ($event->standings) {
            $this->standings($event, $competitionId, $roundIds, $say);
        }

        if ($event->displays) {
            $this->displays($competitionId, $organizerPlayerId, $say);
        }

        $this->urls($event, $competitionId, $say);
    }

    /**
     * @param \Closure(string): void $say
     */
    private function competition(SandboxDemoEvent $event, string $day, string $organizerPlayerId, \Closure $say): string
    {
        $existing = $this->demoData->competitionBySlug($event->slug);
        $competitionId = $existing['id'] ?? null;

        if ($competitionId === null) {
            [$firstDay, $lastDay] = $event->dayRange();
            $competitionId = Uuid::uuid7()->toString();

            $this->messageBus->dispatch(new AddCompetition(
                competitionId: Uuid::fromString($competitionId),
                playerId: $organizerPlayerId,
                name: $event->name,
                shortcut: null,
                description: $event->description,
                link: null,
                registrationLink: null,
                resultsLink: null,
                location: $event->location,
                locationCountryCode: $event->countryCode,
                dateFrom: self::localDay($day, $firstDay, $event->timezone),
                dateTo: self::localDay($day, $lastDay, $event->timezone),
                isOnline: false,
                logo: null,
                maintainerIds: [$organizerPlayerId],
                slug: $event->slug,
                notifyAdmin: false,
            ));
            $say('Event created');
        } else {
            $say('Event exists - completing it');
        }

        if (($existing['approved'] ?? false) === false) {
            $this->messageBus->dispatch(new ApproveCompetition($competitionId, $organizerPlayerId, notifyCreator: false));
        }

        if ($existing !== null && $existing['is_draft']) {
            $say('NOTE: the event is a draft - publish it on its edit page to show it');
        }

        return $competitionId;
    }

    /**
     * @param \Closure(string): void $say
     * @return array<string, string> round key => round id
     */
    private function rounds(SandboxDemoEvent $event, string $competitionId, string $day, \Closure $say): array
    {
        $existing = $this->demoData->rounds($competitionId);
        $roundIds = [];
        $added = 0;

        foreach ($event->rounds as $round) {
            $roundStart = self::roundStart($day, $round, $event->timezone);

            if (isset($existing[$round->name])) {
                $roundIds[$round->key] = $existing[$round->name]['id'];
            } else {
                $roundId = Uuid::uuid7();
                $this->messageBus->dispatch(new AddCompetitionRound(
                    roundId: $roundId,
                    competitionId: $competitionId,
                    name: $round->name,
                    minutesLimit: $round->minutesLimit,
                    startsAt: $roundStart,
                    timezone: $event->timezone,
                    badgeBackgroundColor: null,
                    badgeTextColor: null,
                    category: $round->category,
                    teamSize: $round->teamSize(),
                ));
                $roundIds[$round->key] = $roundId->toString();
                $added++;
            }
        }

        $say(sprintf('Rounds: %d added, %d existed', $added, count($event->rounds) - $added));

        return $roundIds;
    }

    /**
     * The rounds' catalogue puzzles (each round its own, a shared one where shared puzzles allow it), the blocks of
     * challenges as "Solve all N (order recorded)" with their labels, unboxed rounds switched on.
     *
     * @param array<string, string> $roundIds
     * @param \Closure(string): void $say
     */
    private function puzzles(SandboxDemoEvent $event, string $competitionId, string $day, array $roundIds, \Closure $say): void
    {
        $used = [];

        foreach ($roundIds as $roundId) {
            foreach ($this->demoData->roundPuzzles($roundId) as $roundPuzzle) {
                $used[] = $roundPuzzle['puzzle_id'];
            }
        }

        $flags = $this->demoData->rounds($competitionId);

        foreach ($event->rounds as $round) {
            $roundId = $roundIds[$round->key];

            if ($this->demoData->roundPuzzles($roundId) === []) {
                if ($round->sharesPuzzleOf !== null) {
                    $this->sharePuzzle($round, $roundId, $roundIds[$round->sharesPuzzleOf], $event, $say);
                } else {
                    $puzzleIds = $this->demoData->cataloguePuzzles($round->piecesPerPuzzle, $round->puzzles, $event->slug, $used, $round->woodenPuzzle);

                    if (count($puzzleIds) < $round->puzzles) {
                        // A small catalogue (tests): any other puzzle anybody may see
                        $puzzleIds = [...$puzzleIds, ...$this->demoData->cataloguePuzzles(null, $round->puzzles - count($puzzleIds), $event->slug, [...$used, ...$puzzleIds])];
                    }

                    if ($puzzleIds === []) {
                        $say(sprintf('TODO: no catalogue puzzle for "%s" - import the catalogue first', $round->name));

                        continue;
                    }

                    $this->messageBus->dispatch(new SetCompetitionRoundPuzzles($roundId, $puzzleIds));
                    $used = [...$used, ...$puzzleIds];
                }
            }

            $roundPuzzles = $this->demoData->roundPuzzles($roundId);

            if ($round->solveAll && count($roundPuzzles) >= 2 && ($flags[$round->name]['puzzle_format'] ?? null) !== RoundPuzzleFormat::Pick->value) {
                $this->messageBus->dispatch(new EditCompetitionRound(
                    roundId: $roundId,
                    competitionId: $competitionId,
                    name: $round->name,
                    minutesLimit: $round->minutesLimit,
                    // Kept as the round has it (keepFields) - only the format changes
                    startsAt: self::roundStart($day, $round, $event->timezone),
                    timezone: $event->timezone,
                    badgeBackgroundColor: null,
                    badgeTextColor: null,
                    category: $round->category,
                    keepFields: EditCompetitionRound::FIELDS,
                    puzzleFormat: RoundPuzzleFormat::Pick,
                    puzzlesToSolve: count($roundPuzzles),
                ));
            }

            if ($round->labels !== [] && $roundPuzzles !== []) {
                $labels = [];

                foreach ($roundPuzzles as $position => $roundPuzzle) {
                    $labels[$roundPuzzle['id']] = $round->labels[$position] ?? null;
                }

                $this->messageBus->dispatch(new ArrangeRoundPuzzles($roundId, $competitionId, array_column($roundPuzzles, 'id'), $labels));
            }

            if ($round->unboxed && ($flags[$round->name]['unboxed'] ?? false) === false) {
                $this->messageBus->dispatch(new ChangeRoundUnboxed($competitionId, $roundId, true));
            }
        }

        $say('Puzzles, formats, labels and unboxed rounds set');
    }

    /**
     * @param \Closure(string): void $say
     */
    private function sharePuzzle(SandboxDemoRound $round, string $roundId, string $sourceRoundId, SandboxDemoEvent $event, \Closure $say): void
    {
        $sharedPuzzleId = $this->demoData->roundPuzzles($sourceRoundId)[0]['puzzle_id'] ?? null;

        if ($sharedPuzzleId === null) {
            return;
        }

        // Rounds of one category may share a puzzle (shared puzzles S2)
        $this->messageBus->dispatch(new SetCompetitionRoundPuzzles($roundId, [$sharedPuzzleId]));
        $say(sprintf('"%s" shares the puzzle of "%s"', $round->name, $event->round($round->sharesPuzzleOf ?? '')->name));
    }

    /**
     * The participants as an organiser's file: the made-up players (linked to their profiles) first, then made-up
     * guests - through the participants import (`external_id` keeps a re-run from adding anybody twice).
     *
     * @param list<array{id: string, name: string, country: null|string, email: string}> $players
     * @param \Closure(string): void $say
     */
    private function participants(SandboxDemoEvent $event, string $competitionId, array $players, \Closure $say): void
    {
        $lineUp = self::lineUp($event, $players);
        $teamRounds = array_values(array_filter($event->rounds, static fn (SandboxDemoRound $round): bool => $round->category !== RoundCategory::Solo));

        $result = null;
        $path = sys_get_temp_dir() . '/msp-sandbox-demo-' . bin2hex(random_bytes(6)) . '.csv';
        $file = fopen($path, 'wb');
        assert($file !== false);

        try {
            fputcsv($file, ['name', 'country', 'external_id', 'msp_player_id', 'round_names', ...array_map(static fn (SandboxDemoRound $round): string => 'team_name: ' . $round->name, $teamRounds)], escape: '');

            foreach ($lineUp['persons'] as $person) {
                $teams = [];

                foreach ($teamRounds as $round) {
                    $teams[] = $person['teams'][$round->key] ?? '';
                }

                fputcsv($file, [$person['name'], $person['country'] ?? '', $person['externalId'], $person['playerId'] ?? '', implode('; ', $person['rounds']), ...$teams], escape: '');
            }

            fclose($file);

            $result = $this->messageBus->dispatch(new ImportCompetitionParticipants($competitionId, $path))->last(HandledStamp::class)?->getResult();
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }

        assert($result instanceof ParticipantImportResult);
        $linked = count(array_filter($lineUp['persons'], static fn (array $person): bool => $person['playerId'] !== null));
        $say(sprintf('Participants: %d (%d linked to made-up players) - %d added, %d updated, %d unchanged', count($lineUp['persons']), $linked, $result->added, $result->updated, $result->unchanged));

        foreach ($result->errors as $error) {
            $say('Import error: ' . $error->trans($this->translator, 'en'));
        }
    }

    /**
     * The event's divisions, the rounds' subsets (D1) and every entry's division: a group's n-th unit gets the division
     * its rule names, the others the round's first one. Divisions the organiser added meanwhile are kept.
     *
     * @param array<string, string> $roundIds
     * @param list<array{id: string, name: string, country: null|string, email: string}> $players
     * @param \Closure(string): void $say
     */
    private function divisions(SandboxDemoEvent $event, string $competitionId, array $roundIds, array $players, \Closure $say): void
    {
        if ($event->divisions === []) {
            return;
        }

        $inputs = [];
        $subsets = [];
        $ids = [];

        foreach ($this->getCompetitionDivisions->ofCompetition($competitionId) as $division) {
            $ids[$division->name] = $division->id;
            $inputs[$division->name] = new DivisionInput($division->id, $division->name, $division->shortName);

            foreach ($division->roundIds as $roundId) {
                $subsets[$roundId][] = $division->id;
            }
        }

        foreach ($event->divisions as $division) {
            $ids[$division['name']] ??= Uuid::uuid7()->toString();
            $inputs[$division['name']] = new DivisionInput($ids[$division['name']], $division['name'], $division['short']);
        }

        foreach ($event->rounds as $round) {
            if ($round->divisions !== []) {
                $subsets[$roundIds[$round->key]] = array_map(static fn (string $name): string => $ids[$name] ?? '', $round->divisions);
            }
        }

        $this->messageBus->dispatch(new SetCompetitionDivisions($competitionId, array_values($inputs), $subsets));

        $participants = $this->demoData->participantsByExternalId($competitionId);
        $lineUp = self::lineUp($event, $players);
        $assigned = 0;

        foreach ($event->groups as $group) {
            foreach ($group->roundKeys as $roundKey) {
                $round = $event->round($roundKey);

                if ($round->divisions === []) {
                    continue;
                }

                $entries = [];

                foreach ($lineUp['units'][$group->key] ?? [] as $unitIndex => $unit) {
                    $firstMember = $lineUp['persons'][$unit['members'][0] ?? -1] ?? null;
                    $participantId = $firstMember !== null ? ($participants[$firstMember['externalId']] ?? null) : null;

                    if ($participantId !== null) {
                        $entries[] = new EntryDivisionInput($participantId, null, self::unitDivision($group, $unitIndex + 1, $round));
                    }
                }

                if ($entries !== []) {
                    $this->messageBus->dispatch(new SetRoundEntryDivisions($competitionId, $roundIds[$roundKey], $entries));
                    $assigned += count($entries);
                }
            }
        }

        $say(sprintf('Divisions: %d on the event, %d entries given one', count($inputs), $assigned));
    }

    /**
     * The division of a group's n-th unit (1-based) in a round using divisions
     */
    public static function unitDivision(SandboxDemoGroup $group, int $unitNumber, SandboxDemoRound $round): string
    {
        foreach ($group->divisions as $division => $every) {
            if ($every > 0 && $unitNumber % $every === 0 && in_array($division, $round->divisions, true)) {
                return $division;
            }
        }

        return $round->divisions[0] ?? SandboxDemoEventDefinitions::REGULAR;
    }

    /**
     * Who enters which round with whom - deterministic, from the event's groups.
     *
     * @param list<array{id: string, name: string, country: null|string, email: string}> $players
     * @return array{
     *     persons: list<array{externalId: string, name: string, country: null|string, playerId: null|string, rounds: list<string>, teams: array<string, string>}>,
     *     units: array<string, list<array{name: null|string, members: list<int>}>>
     * }
     */
    public static function lineUp(SandboxDemoEvent $event, array $players): array
    {
        $persons = [];
        $units = [];
        $usedNames = [];

        foreach ($event->groups as $groupIndex => $group) {
            if ($group->reuseOf !== null) {
                $pool = array_merge(...array_map(static fn (array $unit): array => $unit['members'], $units[$group->reuseOf] ?? []));
            } else {
                $pool = [];

                for ($i = 0; $i < $group->units * $group->size; $i++) {
                    $index = count($persons);
                    $player = $players[$index] ?? null;
                    $guest = SandboxPeople::person(1000 + $index);
                    $persons[] = [
                        'externalId' => sprintf('demo-%s-%03d', $event->code, $index + 1),
                        'name' => $player['name'] ?? $guest['name'],
                        'country' => $player !== null ? $player['country'] : $guest['country']->name,
                        'playerId' => $player['id'] ?? null,
                        'rounds' => [],
                        'teams' => [],
                    ];
                    $pool[] = $index;
                }
            }

            $units[$group->key] = [];

            foreach (array_slice(array_chunk($pool, $group->size), 0, $group->units) as $unitIndex => $members) {
                $name = null;

                if ($group->size > 1) {
                    $name = self::teamName($group, $groupIndex, $unitIndex, array_map(static fn (int $member): string => $persons[$member]['name'], $members));
                    $base = $name;

                    for ($copy = 2; isset($usedNames[$group->key][$name]); $copy++) {
                        $name = sprintf('%s (%d)', $base, $copy);
                    }

                    $usedNames[$group->key][$name] = true;
                }

                $units[$group->key][] = ['name' => $name, 'members' => $members];

                foreach ($members as $member) {
                    foreach ($group->roundKeys as $roundKey) {
                        $round = $event->round($roundKey);
                        $persons[$member]['rounds'][] = $round->name;

                        if ($name !== null) {
                            $persons[$member]['teams'][$roundKey] = $name;
                        }
                    }
                }
            }
        }

        return ['persons' => array_values($persons), 'units' => $units];
    }

    /**
     * @param list<string> $memberNames
     */
    private static function teamName(SandboxDemoGroup $group, int $groupIndex, int $unitIndex, array $memberNames): string
    {
        $firstNames = array_map(static fn (string $name): string => explode(' ', $name)[0], $memberNames);

        return match ($group->teamNames) {
            SandboxDemoGroup::NAMES_FIRST_NAMES => implode(' & ', $firstNames),
            SandboxDemoGroup::NAMES_FAMILY => sprintf('The %s family', array_reverse(explode(' ', $memberNames[0] ?? 'Demo'))[0]),
            default => sprintf(
                '%s %s',
                self::FUN_FIRST[($unitIndex + 5 * $groupIndex) % count(self::FUN_FIRST)],
                self::FUN_SECOND[(intdiv($unitIndex, count(self::FUN_FIRST)) + $unitIndex * 7 + $groupIndex) % count(self::FUN_SECOND)],
            ),
        };
    }

    /**
     * Tables by name on the first round of each kind, then "Use these tables in later rounds" (W3) for the rest.
     *
     * @param array<string, string> $roundIds
     * @param \Closure(string): void $say
     */
    private function tables(SandboxDemoEvent $event, string $competitionId, array $roundIds, \Closure $say): void
    {
        $copies = [];

        foreach ($event->rounds as $round) {
            if ($round->tablesFrom !== null) {
                $copies[$round->tablesFrom][] = $roundIds[$round->key];

                continue;
            }

            if ($round->firstTable === null) {
                continue;
            }

            $entries = self::byName($this->getRoundResultEntries->forRound($roundIds[$round->key]));
            $taken = array_values(array_filter(array_map(static fn (RoundResultEntry $entry): null|int => $entry->tableNumber, $entries)));
            $next = max($round->firstTable - 1, ...($taken === [] ? [0] : $taken)) + 1;
            $assignments = [];

            foreach ($entries as $entry) {
                if ($entry->tableNumber === null) {
                    $assignments[] = ['entry' => $entry->ref->toString(), 'from' => null, 'number' => $next++];
                }
            }

            if ($assignments !== []) {
                $this->messageBus->dispatch(new AssignTableNumbers($competitionId, $roundIds[$round->key], $assignments));
                $say(sprintf('Tables of "%s": %d assigned', $round->name, count($assignments)));
            }
        }

        foreach ($copies as $sourceKey => $targetIds) {
            $targets = [];

            foreach ($this->sameTablesCopy->plan($competitionId, $roundIds[$sourceKey])['targets'] as $target) {
                if (in_array($target->roundId, $targetIds, true) && $target->changes > 0) {
                    $targets[$target->roundId] = $target->hash;
                }
            }

            if ($targets !== []) {
                $this->messageBus->dispatch(new CopyRoundTableNumbers($competitionId, $roundIds[$sourceKey], $targets));
                $say(sprintf('Tables of "%s" copied to %d later rounds', $event->round($sourceKey)->name, count($targets)));
            }
        }
    }

    /**
     * @param array<string, string> $roundIds
     * @param \Closure(string): void $say
     */
    private function results(SandboxDemoEvent $event, string $competitionId, array $roundIds, string $organizerPlayerId, \Closure $say): void
    {
        $overview = [];

        foreach ($this->getRoundResultsOverview->forCompetition($competitionId) as $round) {
            $overview[$round->roundId] = $round;
        }

        foreach ($event->rounds as $round) {
            $roundId = $roundIds[$round->key];
            $changes = SandboxDemoResultPlanner::changes(
                $round,
                $this->getRoundResultEntries->forRound($roundId),
                $this->demoData->roundPuzzles($roundId),
            );

            if ($changes !== []) {
                $recorded = $this->messageBus->dispatch(new RecordRoundResults($competitionId, $roundId, $organizerPlayerId, $changes))->last(HandledStamp::class)?->getResult();
                assert($recorded instanceof RecordedRoundResults);
                $refused = array_values(array_filter($recorded->outcomes, static fn (RoundResultChangeOutcome $outcome): bool => $outcome->status === RoundResultChangeStatus::Rejected));
                $say(sprintf(
                    'Results of "%s": %d recorded%s',
                    $round->name,
                    $recorded->countOf(RoundResultChangeStatus::Applied),
                    $refused !== [] ? sprintf(', %d refused (%s)', count($refused), implode(', ', array_unique(array_map(static fn (RoundResultChangeOutcome $outcome): string => $outcome->reason ?? '?', $refused)))) : '',
                ));
            }

            $roundOverview = $overview[$roundId] ?? null;

            if ($round->state === SandboxDemoRound::PUBLISHED && ($roundOverview === null || $roundOverview->resultsPublishedAt === null)) {
                $this->messageBus->dispatch(new PublishRoundResults($competitionId, $roundId));
                $say(sprintf('Results of "%s" published', $round->name));
            }
        }
    }

    /**
     * One cumulative standing per category, World Series rules: 5 s per missing piece, the total only, pieces missing
     * from a finished puzzle cost too, a no-show counts as nothing placed.
     *
     * @param array<string, string> $roundIds
     * @param \Closure(string): void $say
     */
    private function standings(SandboxDemoEvent $event, string $competitionId, array $roundIds, \Closure $say): void
    {
        $existing = $this->demoData->standings($competitionId);

        foreach ([RoundCategory::Solo, RoundCategory::Duo, RoundCategory::Team] as $category) {
            $rounds = array_values(array_filter($event->rounds, static fn (SandboxDemoRound $round): bool => $round->category === $category));

            if ($rounds === []) {
                continue;
            }

            $name = sprintf(self::STANDING_NAME, self::categoryName($category));
            $standingId = $existing[$name]['id'] ?? null;

            if ($standingId === null) {
                $standingId = Uuid::uuid7()->toString();
                $this->messageBus->dispatch(new CreateCompetitionStanding(
                    standingId: Uuid::fromString($standingId),
                    competitionId: $competitionId,
                    name: $name,
                    category: $category,
                    roundIds: array_map(static fn (SandboxDemoRound $round): string => $roundIds[$round->key], $rounds),
                    rules: new StandingRules(
                        penaltySecondsPerPiece: 5,
                        order: StandingOrder::TotalOnly,
                        missing: StandingMissing::AsZeroPieces,
                        penaliseFinishedMissing: true,
                    ),
                ));
                $say(sprintf('Standing "%s" created', $name));
            }

            if (($existing[$name]['published'] ?? false) === false) {
                $this->messageBus->dispatch(new PublishCompetitionStanding($standingId, $competitionId));
            }
        }
    }

    /**
     * A timer screen with the live top 10 and an OBS overlay with the live board plus the standing of the round's
     * category (the v2 `standings` element, "auto").
     *
     * @param \Closure(string): void $say
     */
    private function displays(string $competitionId, string $organizerPlayerId, \Closure $say): void
    {
        $existing = $this->demoData->displays($competitionId);

        if (!isset($existing['Hall timer screen'])) {
            $this->messageBus->dispatch(new AddCompetitionDisplay(Uuid::uuid7(), $competitionId, DisplayKind::Screen, 'Hall timer screen', 'en', $organizerPlayerId, DisplayPreset::TimerWithLiveBoard));
            $say('Display "Hall timer screen" added');
        }

        if (!isset($existing['Stream overlay'])) {
            $displayId = Uuid::uuid7();
            $this->messageBus->dispatch(new AddCompetitionDisplay($displayId, $competitionId, DisplayKind::Overlay, 'Stream overlay', 'en', $organizerPlayerId, DisplayPreset::ObsLeaderboardSidebar));

            $design = DisplayPresets::definition(DisplayPreset::ObsLeaderboardSidebar, DisplayKind::Overlay);
            $elements = is_array($design['elements'] ?? null) ? $design['elements'] : [];
            $elements[] = [
                'id' => 'standng1',
                'type' => 'standings',
                'name' => 'Championship standing',
                'frame' => ['x' => 3, 'y' => 52, 'w' => 30, 'h' => 44, 'rotate' => 0],
                'z' => 6,
                'style' => ['fontSize' => 26, 'align' => 'left'],
                'props' => ['standingId' => 'auto', 'rows' => 8, 'columns' => ['rank', 'flag', 'name', 'total']],
            ];
            $design['elements'] = $elements;

            $this->messageBus->dispatch(new SaveCompetitionDisplayDesign(
                displayId: $displayId->toString(),
                design: json_decode(json_encode($design, JSON_THROW_ON_ERROR), false, flags: JSON_THROW_ON_ERROR),
                baseVersion: 1,
                liveConfirmed: true,
            ));
            $say('Display "Stream overlay" added (with the standings element)');
        }
    }

    /**
     * @param \Closure(string): void $say
     */
    private function urls(SandboxDemoEvent $event, string $competitionId, \Closure $say): void
    {
        $url = $this->url(...);

        $say('URLs:');
        $say('  Event page        ' . $url('event_detail', ['slug' => $event->slug]));
        $say('  Toolkit hub       ' . $url('competition_toolkit', ['competitionId' => $competitionId]));
        $say('  Participants      ' . $url('participants_sheet', ['competitionId' => $competitionId]));
        $say('  Competitor list   ' . $url(CompetitorListController::ROUTE, ['competitionId' => $competitionId]));
        $say('  Standings (edit)  ' . $url('competition_standings_manage', ['competitionId' => $competitionId]));

        foreach ($this->demoData->standings($competitionId) as $name => $standing) {
            $say(sprintf('  Standing          %s  (%s)', $url('event_standing', ['slug' => $event->slug, 'standingSlug' => $standing['slug']]), $name));
        }

        foreach ($this->demoData->displays($competitionId) as $name => $display) {
            $say(sprintf('  Display           %s  (%s)', $url('competition_display', ['token' => $display['token']], false), $name));
        }

        foreach ($this->getRoundResultsOverview->forCompetition($competitionId) as $round) {
            $say(sprintf('  Round "%s"', $round->name));
            $say('    desk            ' . $url('results_desk', ['roundId' => $round->roundId]));
            $say('    judge page      ' . $url('live_results', ['roundId' => $round->roundId]));
            $say('    stopwatch       ' . $url('manage_round_stopwatch', ['roundId' => $round->roundId]));
            $say('    seating         ' . $url('round_seating', ['roundId' => $round->roundId]));

            if ($round->slug !== null) {
                $say('    results         ' . $url('event_round_results', ['slug' => $event->slug, 'roundSlug' => $round->slug]));
                $say('    analytics       ' . $url('event_analytics_studio', ['slug' => $event->slug, 'roundSlug' => $round->slug]));
            }
        }
    }

    /**
     * @param array<string, string> $parameters
     */
    private function url(string $route, array $parameters, bool $localized = true): string
    {
        return $this->urlGenerator->generate(
            $route,
            $localized ? [...$parameters, '_locale' => 'en'] : $parameters,
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    /**
     * @param list<RoundResultEntry> $entries
     * @return list<RoundResultEntry>
     */
    private static function byName(array $entries): array
    {
        usort($entries, static fn (RoundResultEntry $a, RoundResultEntry $b): int => strcmp((string) $a->name, (string) $b->name) ?: strcmp($a->ref->toString(), $b->ref->toString()));

        return $entries;
    }

    private static function categoryName(RoundCategory $category): string
    {
        return match ($category) {
            RoundCategory::Solo => 'Individual',
            RoundCategory::Duo => 'Pairs',
            RoundCategory::Team => 'Teams',
        };
    }

    /**
     * Midnight of the demo day + $offset days in the event's zone
     */
    private static function localDay(string $day, int $offset, string $timezone): DateTimeImmutable
    {
        return (new DateTimeImmutable($day, new DateTimeZone($timezone)))->modify(sprintf('%+d days', $offset));
    }

    /**
     * The round's start as an instant (UTC): its local time on its day in the event's zone
     */
    public static function roundStart(string $day, SandboxDemoRound $round, string $timezone): DateTimeImmutable
    {
        [$hours, $minutes] = array_map('intval', explode(':', $round->time));

        return self::localDay($day, $round->dayOffset, $timezone)->setTime($hours, $minutes)->setTimezone(new DateTimeZone('UTC'));
    }
}
