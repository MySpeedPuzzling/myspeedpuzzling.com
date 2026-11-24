<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Sandbox;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\CompetitorPage\CompetitorListController;
use SpeedPuzzling\Web\Controller\Standings\StandingEmbedController;
use SpeedPuzzling\Web\Exceptions\SandboxModeIsOff;
use SpeedPuzzling\Web\Message\AddCompetition;
use SpeedPuzzling\Web\Message\AddCompetitionDisplay;
use SpeedPuzzling\Web\Message\AddOrganization;
use SpeedPuzzling\Web\Message\AddOrganizationMaintainer;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Message\AssignEventToOrganization;
use SpeedPuzzling\Web\Message\ChangeRoundPuzzleReveal;
use SpeedPuzzling\Web\Message\AddCompetitionReferee;
use SpeedPuzzling\Web\Message\AddCompetitionRound;
use SpeedPuzzling\Web\Message\ApproveCompetition;
use SpeedPuzzling\Web\Message\ArrangeRoundPuzzles;
use SpeedPuzzling\Web\Message\AssignTableNumbers;
use SpeedPuzzling\Web\Message\ChangeCompetitionDisplayFollow;
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
use SpeedPuzzling\Web\Message\SetRoundTableLabels;
use SpeedPuzzling\Web\Message\StartSandboxDemoRoundStopwatch;
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
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\CompetitorPageSettings;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\OrganizationItemKind;
use SpeedPuzzling\Web\Value\OrganizationKind;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use SpeedPuzzling\Web\Value\TableLabelGenerator;
use SpeedPuzzling\Web\Value\DisplayFollowMode;
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
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
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
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param null|string $date the demo day (Y-m-d) - null = today in each event's zone
     * @param \Closure(string): void $say one line of progress for the console
     * @param null|string $onlySlug build only the event of this slug (any known one), null = the default ones (all())
     * @param list<string> $maintainerIds more organisers of a new event and of its organization (players' ids)
     * @param null|string $logoPath the logo of a new event and of its new organization (a file on the box - never in
     *     the repository)
     *
     * @throws SandboxModeIsOff
     */
    public function setUp(
        null|string $date,
        string $organizerPlayerId,
        null|string $refereePlayerId,
        \Closure $say,
        null|string $onlySlug = null,
        array $maintainerIds = [],
        null|string $logoPath = null,
    ): void {
        $this->sandboxMode->assertOn();

        $people = array_values(array_filter(
            $this->simulationData->demoPeople(),
            static fn (array $person): bool => $person['id'] !== $organizerPlayerId && $person['id'] !== $refereePlayerId,
        ));

        $events = array_values(array_filter(
            $onlySlug === null ? SandboxDemoEventDefinitions::all() : SandboxDemoEventDefinitions::known(),
            static fn (SandboxDemoEvent $event): bool => $onlySlug === null || $event->slug === $onlySlug,
        ));

        if ($events === []) {
            throw new \InvalidArgumentException(sprintf('No demo event %s', (string) $onlySlug));
        }

        foreach ($events as $event) {
            $day = $date ?? $this->clock->now()->setTimezone(new DateTimeZone($event->timezone))->format('Y-m-d');
            $say('');
            $say(sprintf('== %s (demo day %s, %s)', $event->name, $day, $event->timezone));
            $this->setUpEvent($event, $day, $organizerPlayerId, $refereePlayerId, $people, $say, $maintainerIds, $logoPath);
        }
    }

    /**
     * @param list<array{id: string, name: string, country: null|string, email: string}> $people
     * @param \Closure(string): void $say
     * @param list<string> $maintainerIds
     */
    private function setUpEvent(SandboxDemoEvent $event, string $day, string $organizerPlayerId, null|string $refereePlayerId, array $people, \Closure $say, array $maintainerIds, null|string $logoPath): void
    {
        $isNew = $this->demoData->competitionBySlug($event->slug) === null;
        $competitionId = $this->competition($event, $day, $organizerPlayerId, $say, $maintainerIds, $logoPath);

        // The old demos keep getting their settings on every run (as built); the SPWS demo only once - a switch the
        // organisers turn during a rehearsal stays
        if ($isNew || $event->organization === null) {
            $this->messageBus->dispatch(new ChangeCompetitorPageSettings($competitionId, new CompetitorPageSettings(
                competitorPages: true,
                liveResultsBeforePublishing: true,
                timeProposals: true,
                qrTagMode: QrTagMode::Entrant,
            )));
        }

        if ($event->organization !== null) {
            $this->organization($event->organization, $competitionId, $organizerPlayerId, $maintainerIds, $say);
        }

        if ($refereePlayerId !== null) {
            $this->messageBus->dispatch(new AddCompetitionReferee($competitionId, $refereePlayerId, $organizerPlayerId));
        }

        $roundIds = $this->rounds($event, $competitionId, $day, $say);
        $this->puzzles($event, $competitionId, $day, $roundIds, $organizerPlayerId, $say);
        $this->participants($event, $competitionId, $people, $say);
        $this->divisions($event, $competitionId, $roundIds, $people, $say);
        $this->tables($event, $competitionId, $roundIds, $say);
        $this->results($event, $competitionId, $roundIds, $organizerPlayerId, $say);
        $this->stopwatches($event, $roundIds, $say);

        if ($event->standings) {
            $this->standings($event, $competitionId, $roundIds, $say);
        }

        if ($event->displays) {
            $this->displays($competitionId, $organizerPlayerId, $say);
        }

        if ($event->brandedDisplays) {
            $this->brandedDisplays($competitionId, SandboxDemoDisplays::all($roundIds), $organizerPlayerId, $say);
        } else {
            if ($event->winterDisplays) {
                $this->brandedDisplays($competitionId, SandboxDemoDisplays::winter($roundIds), $organizerPlayerId, $say);
            }

            // Real events L2b: a board per challenge of the first block, a board per division of the round with the most
            $this->viewDisplays($event, $competitionId, $roundIds, $organizerPlayerId, $say);
        }

        $this->urls($event, $competitionId, $say);
    }

    /**
     * @param \Closure(string): void $say
     * @param list<string> $maintainerIds
     */
    private function competition(SandboxDemoEvent $event, string $day, string $organizerPlayerId, \Closure $say, array $maintainerIds = [], null|string $logoPath = null): string
    {
        $existing = $this->demoData->competitionBySlug($event->slug);
        $competitionId = $existing['id'] ?? null;

        if ($competitionId === null) {
            [$firstDay, $lastDay] = $event->dayRange();
            $competitionId = Uuid::uuid7()->toString();

            $logo = self::logoUpload($logoPath);
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
                logo: $logo,
                maintainerIds: array_values(array_unique([$organizerPlayerId, ...$maintainerIds])),
                slug: $event->slug,
                notifyAdmin: false,
            ));

            if ($logo !== null && is_file($logo->getPathname())) {
                unlink($logo->getPathname());
            }
            $say(sprintf('Event created%s', $logoPath !== null ? ' with its logo' : ''));
        } else {
            $say('Event exists - completing it');

            if ($maintainerIds !== [] || $logoPath !== null) {
                $say('NOTE: --maintainer and --logo shape a new event only - an existing one keeps its team and logo (its organization gets the maintainers)');
            }
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
    private function puzzles(SandboxDemoEvent $event, string $competitionId, string $day, array $roundIds, string $organizerPlayerId, \Closure $say): void
    {
        $used = [];

        foreach ($roundIds as $roundId) {
            foreach ($this->demoData->roundPuzzles($roundId) as $roundPuzzle) {
                $used[] = $roundPuzzle['puzzle_id'];
            }
        }

        $flags = $this->demoData->rounds($competitionId);
        /** @var array<string, list<string>> $intendedOrder round id => its new rows' or puzzles' ids in the definition's order */
        $intendedOrder = [];

        foreach ($event->rounds as $round) {
            $roundId = $roundIds[$round->key];

            $hadPuzzles = $this->demoData->roundPuzzles($roundId) !== [];

            if ($hadPuzzles === false) {
                if ($round->secretPuzzles !== []) {
                    $intendedOrder[$roundId] = $this->addSecretPuzzles($round, $roundId, $organizerPlayerId, $say);
                } elseif ($round->sharesPuzzleOf !== null) {
                    $this->sharePuzzle($round, $roundId, $roundIds[$round->sharesPuzzleOf], $event, $say);
                } elseif ($round->brands !== []) {
                    $puzzleIds = $this->brandedCataloguePuzzles($round, $event->slug, $used);

                    if ($puzzleIds === []) {
                        $say(sprintf('TODO: no catalogue puzzle for "%s" - import the catalogue first', $round->name));

                        continue;
                    }

                    $this->messageBus->dispatch(new SetCompetitionRoundPuzzles($roundId, $puzzleIds));
                    $used = [...$used, ...$puzzleIds];
                    $intendedOrder[$roundId] = $puzzleIds;
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

            if ($hadPuzzles === false) {
                // The adds persist the rows without touching the round's loaded collection (a web request starts with
                // an empty EntityManager, this one command does not): forget what is loaded, the next handlers read fresh
                $this->entityManager->clear();
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

            // Puzzles just added have no position yet (ordered by their row id): put them in the definition's order
            if (isset($intendedOrder[$roundId])) {
                $order = array_flip($intendedOrder[$roundId]);
                usort($roundPuzzles, static fn (array $a, array $b): int => ($order[$a['id']] ?? $order[$a['puzzle_id']] ?? PHP_INT_MAX) <=> ($order[$b['id']] ?? $order[$b['puzzle_id']] ?? PHP_INT_MAX));
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

            // SPWS: one challenge of a back-to-back block unboxed on its own (spws-unboxed-per-puzzle.md) - set with the
            // puzzles, so what the organisers change later stays
            if ($round->unboxedLabels !== [] && $roundPuzzles !== [] && $hadPuzzles === false) {
                $wanted = [];
                $current = [];

                foreach ($roundPuzzles as $position => $roundPuzzle) {
                    if (in_array($round->labels[$position] ?? null, $round->unboxedLabels, true)) {
                        $wanted[] = $roundPuzzle['id'];
                    }

                    if ($roundPuzzle['unboxed']) {
                        $current[] = $roundPuzzle['id'];
                    }
                }

                sort($wanted);
                sort($current);

                if ($wanted !== $current) {
                    $this->messageBus->dispatch(new ChangeRoundUnboxed($competitionId, $roundId, false, $wanted));
                }
            }

            // Secret puzzles revealed when the stopwatch starts with the tick (round-formats.md) - while not yet so
            foreach ($round->secretPuzzles !== [] ? $roundPuzzles : [] as $roundPuzzle) {
                if ($roundPuzzle['secret'] && $roundPuzzle['reveal_mode'] !== RoundPuzzleReveal::Stopwatch->value) {
                    try {
                        $this->messageBus->dispatch(new ChangeRoundPuzzleReveal($roundPuzzle['id'], PuzzleHideMode::Entirely, RoundPuzzleReveal::Stopwatch));
                    } catch (HandlerFailedException $exception) {
                        // A rehearsal started the stopwatch or revealed the puzzle - it stays as it is
                        $say(sprintf('NOTE: "%s" keeps its reveal (%s)', $round->name, $exception->getPrevious()?->getMessage() ?? $exception->getMessage()));
                    }
                }
            }
        }

        $say('Puzzles, formats, labels, unboxed puzzles and reveals set');
    }

    /**
     * Catalogue stand-ins of the brand each puzzle position names (any brand when none, or none of it is left), in the
     * definition's order.
     *
     * @param list<string> $used
     * @return list<string> puzzle ids
     */
    private function brandedCataloguePuzzles(SandboxDemoRound $round, string $salt, array $used): array
    {
        $puzzleIds = [];

        for ($position = 0; $position < $round->puzzles; $position++) {
            $brand = $round->brands[$position] ?? null;
            $pieces = $round->piecesOf($position);
            $except = [...$used, ...$puzzleIds];
            $found = $brand !== null ? $this->demoData->cataloguePuzzles($pieces, 1, $salt, $except, brand: $brand) : [];
            // A small catalogue (tests): any puzzle of the pieces, then any puzzle anybody may see
            $found = $found !== [] ? $found : $this->demoData->cataloguePuzzles($pieces, 1, $salt, $except);
            $found = $found !== [] ? $found : $this->demoData->cataloguePuzzles(null, 1, $salt, $except);

            if ($found === []) {
                break;
            }

            $puzzleIds[] = $found[0];
        }

        return $puzzleIds;
    }

    /**
     * New puzzles made for the round and kept secret (hidden entirely) - the way the organiser's "Add a puzzle" with
     * "Keep it secret" does it: a yes to the round's automatic reveal as read now, switched to the stopwatch reveal
     * afterwards (puzzles()).
     *
     * @param \Closure(string): void $say
     * @return list<string> the new rows' ids in the definition's order
     */
    private function addSecretPuzzles(SandboxDemoRound $round, string $roundId, string $organizerPlayerId, \Closure $say): array
    {
        $userId = $this->demoData->userIdOfPlayer($organizerPlayerId);
        $reveal = $this->demoData->roundReveal($roundId);

        if ($userId === null || $reveal === null) {
            $say(sprintf('TODO: "%s" - the organiser has no account, no secret puzzles added', $round->name));

            return [];
        }

        $startsAt = new DateTimeImmutable($reveal['starts_at'], new DateTimeZone('UTC'));

        // Its automatic reveal must still be ahead, or the puzzle would be public the moment it is made
        if ($startsAt <= $this->clock->now()) {
            $say(sprintf('TODO: "%s" has started already - no secret puzzles added (delete the event and build it again with a later --date)', $round->name));

            return [];
        }

        $automaticRevealAt = RoundPuzzleReveal::automaticRevealAt($startsAt, $reveal['reveal_delay_minutes']);
        $ids = [];

        foreach ($round->secretPuzzles as $position => $secret) {
            $roundPuzzleId = Uuid::uuid7();
            $this->messageBus->dispatch(new AddPuzzleToCompetitionRound(
                roundPuzzleId: $roundPuzzleId,
                roundId: $roundId,
                userId: $userId,
                brand: $secret['brand'],
                puzzle: $secret['name'],
                piecesCount: $round->piecesOf($position),
                // A made-up box picture, so an unboxed challenge shows the difference before / after its results
                puzzlePhoto: SandboxPlaceholderBox::upload($secret['name']),
                eans: EanList::fromInputs([]),
                brandCodes: BrandCodeList::fromInputs([]),
                hideUntilRoundStarts: true,
                hideMode: PuzzleHideMode::Entirely,
                shownAutomaticRevealAt: $automaticRevealAt,
            ));
            $ids[] = $roundPuzzleId->toString();
        }

        $say(sprintf('"%s": %d new secret puzzles', $round->name, count($ids)));

        return $ids;
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
        /** @var list<array{externalId: string, name: string, country: null|string, playerId: null|string}> $people */
        $people = [];
        /** @var array<int, list<string>> $rounds */
        $rounds = [];
        /** @var array<int, array<string, string>> $teams */
        $teams = [];
        $units = [];
        $usedNames = [];

        foreach ($event->groups as $groupIndex => $group) {
            if ($group->reuseOf !== null) {
                $pool = array_merge(...array_map(static fn (array $unit): array => $unit['members'], $units[$group->reuseOf] ?? []));
            } else {
                $pool = [];

                for ($i = 0; $i < $group->units * $group->size; $i++) {
                    $index = count($people);
                    $player = $players[$index] ?? null;
                    $guest = SandboxPeople::person(1000 + $index);
                    $people[] = [
                        'externalId' => sprintf('demo-%s-%03d', $event->code, $index + 1),
                        'name' => $player !== null ? $player['name'] : $guest['name'],
                        'country' => $player !== null ? $player['country'] : $guest['country']->name,
                        'playerId' => $player !== null ? $player['id'] : null,
                    ];
                    $pool[] = $index;
                }
            }

            $units[$group->key] = [];

            foreach (array_slice(array_chunk($pool, max(1, $group->size)), 0, $group->units) as $unitIndex => $members) {
                $name = null;

                if ($group->size > 1) {
                    $name = self::teamName($group, $groupIndex, $unitIndex, array_map(static fn (int $member): string => $people[$member]['name'] ?? '', $members));
                    $base = $name;

                    // Unique within each round the group enters - two groups may share a round (Carnival 2027: the
                    // 1000 has the CHALLENGE teams and teams of its own); a name is a team in the participants import
                    $taken = static function (string $candidate) use (&$usedNames, $group): bool {
                        foreach ($group->roundKeys as $roundKey) {
                            if (isset($usedNames[$roundKey][$candidate])) {
                                return true;
                            }
                        }

                        return false;
                    };

                    // Another fun name first (a fun name is never taken within its own group), then a number
                    for ($shift = 1; $group->teamNames === SandboxDemoGroup::NAMES_FUN && $shift < 12 && $taken($name); $shift++) {
                        $name = self::teamName($group, $groupIndex + $shift, $unitIndex, []);
                    }

                    if ($taken($name)) {
                        $name = $base;
                    }

                    for ($copy = 2; $taken($name); $copy++) {
                        $name = sprintf('%s (%d)', $base, $copy);
                    }

                    foreach ($group->roundKeys as $roundKey) {
                        $usedNames[$roundKey][$name] = true;
                    }
                }

                $units[$group->key][] = ['name' => $name, 'members' => $members];

                foreach ($members as $member) {
                    foreach ($group->roundKeys as $roundKey) {
                        $round = $event->round($roundKey);
                        $rounds[$member][] = $round->name;

                        if ($name !== null) {
                            $teams[$member][$roundKey] = $name;
                        }
                    }
                }
            }
        }

        $persons = [];

        foreach ($people as $index => $person) {
            $persons[] = [
                'externalId' => $person['externalId'],
                'name' => $person['name'],
                'country' => $person['country'],
                'playerId' => $person['playerId'],
                'rounds' => $rounds[$index] ?? [],
                'teams' => $teams[$index] ?? [],
            ];
        }

        return ['persons' => $persons, 'units' => $units];
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
        $this->tableLabels($event, $competitionId, $roundIds, $say);
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

            if ($this->waitsForItsDay($round, $roundId, $event->timezone)) {
                $say(sprintf('"%s" is the demo day\'s running block - the day is ahead: no results, not started (start it yourself)', $round->name));

                continue;
            }

            $changes = SandboxDemoResultPlanner::changes(
                $round,
                $this->getRoundResultEntries->forRound($roundId),
                array_map(static fn (array $roundPuzzle): array => ['id' => $roundPuzzle['id'], 'pieces' => $roundPuzzle['pieces']], $this->demoData->roundPuzzles($roundId)),
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
     * A demo built on a day still ahead (`--date` after today in the event's zone - e.g. the real SPWS dates, months
     * ahead, so the organisers try it now, starting any stopwatch early): the running round with `stopwatchFromStart`
     * gets neither seeded results nor a stopwatch - seeded splits would be later than a stopwatch started live today and
     * block "Finished now", and a start scheduled months ahead would refuse their own Start (spws-demo-ux-leftovers.md
     * §5). Nothing else of the demo depends on today: earlier rounds are recorded and published as always. Today or a
     * day past: as designed (results seeded, the stopwatch from the round's start).
     */
    private function waitsForItsDay(SandboxDemoRound $round, string $roundId, string $timezone): bool
    {
        if ($round->state !== SandboxDemoRound::RUNNING || $round->stopwatchFromStart === false) {
            return false;
        }

        // The round's stored day - a re-run with another --date never moves an existing round, so it decides
        $stored = $this->demoData->roundReveal($roundId);

        if ($stored === null) {
            return false;
        }

        $zone = new DateTimeZone($timezone);
        $roundDay = new DateTimeImmutable($stored['starts_at'], new DateTimeZone('UTC'))->setTimezone($zone)->format('Y-m-d');

        return $roundDay > $this->clock->now()->setTimezone($zone)->format('Y-m-d');
    }

    /**
     * A running round with `stopwatchFromStart`: its stopwatch runs from the round's stored start (started at that moment
     * when it has passed, scheduled for it while ahead - StartSandboxDemoRoundStopwatch), so the judge's "Finished now"
     * works and the seeded splits lie in the clock's past (spws-demo-ux-leftovers.md §5). A stopwatch with a status (a
     * rehearsal, an earlier run) is left alone.
     *
     * @param array<string, string> $roundIds
     * @param \Closure(string): void $say
     */
    private function stopwatches(SandboxDemoEvent $event, array $roundIds, \Closure $say): void
    {
        foreach ($event->rounds as $round) {
            if ($round->state !== SandboxDemoRound::RUNNING || $round->stopwatchFromStart === false || $this->waitsForItsDay($round, $roundIds[$round->key], $event->timezone)) {
                continue;
            }

            $roundId = $roundIds[$round->key];
            $stored = $this->demoData->roundReveal($roundId);

            if ($stored === null) {
                continue;
            }

            // The round's start as stored - a re-run on another --date never moves an existing round
            $startsAt = new DateTimeImmutable($stored['starts_at'], new DateTimeZone('UTC'));
            $changed = $this->messageBus->dispatch(new StartSandboxDemoRoundStopwatch($roundId, $startsAt))->last(HandledStamp::class)?->getResult();

            if ($changed === true) {
                $say(sprintf(
                    'Stopwatch of "%s" %s %s (%s)',
                    $round->name,
                    $startsAt > $this->clock->now() ? 'scheduled to start at' : 'started at',
                    $startsAt->setTimezone(new DateTimeZone($event->timezone))->format('Y-m-d H:i'),
                    $event->timezone,
                ));
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
     * Real events L2b (displays.md "Division and challenge views"): "Challenges board" - pinned to the event's first
     * solve-all block, a leaderboard per challenge ranked by that puzzle's own time; "Divisions board" - pinned to the
     * round using the most divisions, a leaderboard per division (up to 3) with the division ranks. Found again by name.
     *
     * @param array<string, string> $roundIds round key => round id
     * @param \Closure(string): void $say
     */
    private function viewDisplays(SandboxDemoEvent $event, string $competitionId, array $roundIds, string $organizerPlayerId, \Closure $say): void
    {
        $existing = $this->demoData->displays($competitionId);
        $block = null;
        $divided = null;

        foreach ($event->rounds as $round) {
            if ($block === null && $round->solveAll && $round->puzzles >= 2) {
                $block = $round;
            }

            // The round with the most divisions (the first of equals)
            if (count($round->divisions) >= 2 && ($divided === null || count($round->divisions) > count($divided->divisions))) {
                $divided = $round;
            }
        }

        $boards = [];

        if ($block !== null && isset($roundIds[$block->key]) && !isset($existing['Challenges board'])) {
            $columns = [];

            for ($position = 1; $position <= min(3, $block->puzzles); $position++) {
                $columns[] = [$block->labels[$position - 1] ?? 'Puzzle ' . $position, ['challenge' => $position]];
            }

            $boards['Challenges board'] = [$roundIds[$block->key], $columns];
        }

        if ($divided !== null && isset($roundIds[$divided->key]) && !isset($existing['Divisions board'])) {
            $ids = [];
            foreach ($this->getCompetitionDivisions->ofCompetition($competitionId) as $division) {
                $ids[$division->name] = $division->id;
            }

            $columns = [];
            foreach (array_slice($divided->divisions, 0, 3) as $name) {
                if (isset($ids[$name])) {
                    $columns[] = [$name, ['division' => $ids[$name]]];
                }
            }

            $boards['Divisions board'] = [$roundIds[$divided->key], $columns];
        }

        foreach ($boards as $name => [$roundId, $columns]) {
            $displayId = Uuid::uuid7();
            $this->messageBus->dispatch(new AddCompetitionDisplay($displayId, $competitionId, DisplayKind::Screen, $name, 'en', $organizerPlayerId, DisplayPreset::Blank));
            $this->messageBus->dispatch(new ChangeCompetitionDisplayFollow($displayId->toString(), DisplayFollowMode::Pinned, $roundId));

            $elements = [[
                'id' => 'roundnm1',
                'type' => 'field',
                'name' => 'Round',
                'frame' => ['x' => 3, 'y' => 3, 'w' => 94, 'h' => 10, 'rotate' => 0],
                'style' => ['fontSize' => 64, 'fontWeight' => 700, 'align' => 'center'],
                'props' => ['field' => 'round_name'],
            ]];
            $width = 94 / max(1, count($columns));

            foreach ($columns as $index => [$title, $view]) {
                $x = round(3 + $index * $width, 2);
                $elements[] = [
                    'id' => sprintf('title%03d', $index),
                    'type' => 'text',
                    'name' => $title,
                    'frame' => ['x' => $x, 'y' => 16, 'w' => round($width - 2, 2), 'h' => 7, 'rotate' => 0],
                    'style' => ['fontSize' => 44, 'align' => 'center'],
                    'props' => ['text' => $title],
                ];
                $elements[] = [
                    'id' => sprintf('board%03d', $index),
                    'type' => 'leaderboard',
                    'name' => $title,
                    'frame' => ['x' => $x, 'y' => 25, 'w' => round($width - 2, 2), 'h' => 72, 'rotate' => 0],
                    'style' => ['fontSize' => 32],
                    'props' => ['rows' => 10, 'columns' => ['rank', 'name', 'result'], ...$view],
                ];
            }

            $design = DisplayPresets::definition(DisplayPreset::Blank, DisplayKind::Screen);
            $design['elements'] = $elements;

            $this->messageBus->dispatch(new SaveCompetitionDisplayDesign(
                displayId: $displayId->toString(),
                design: json_decode(json_encode($design, JSON_THROW_ON_ERROR), false, flags: JSON_THROW_ON_ERROR),
                baseVersion: 1,
            ));
            $say(sprintf('Display "%s" added', $name));
        }
    }

    /**
     * SPWS hall table names (table-labels-and-plates.md) on every round of a kind whose first round asks for them -
     * one list for all of them, so "Use these tables in later rounds" keeps the names. Only while none of those rounds
     * has names yet: a scheme the organisers chose meanwhile is theirs.
     *
     * @param array<string, string> $roundIds
     * @param \Closure(string): void $say
     */
    private function tableLabels(SandboxDemoEvent $event, string $competitionId, array $roundIds, \Closure $say): void
    {
        $current = [];

        foreach ($this->demoData->rounds($competitionId) as $round) {
            $current[$round['id']] = $round['table_labels'];
        }

        foreach ($event->rounds as $round) {
            if ($round->seatsPerTable === null) {
                continue;
            }

            $ids = [$roundIds[$round->key]];

            foreach ($event->rounds as $later) {
                if ($later->tablesFrom === $round->key) {
                    $ids[] = $roundIds[$later->key];
                }
            }

            // Only before anybody sits anywhere: names cleared by the organisers stay cleared
            foreach ($this->getRoundResultEntries->forRound($roundIds[$round->key]) as $entry) {
                if ($entry->tableNumber !== null) {
                    continue 2;
                }
            }

            $expected = [];

            foreach ($ids as $id) {
                if (($current[$id] ?? null) !== null) {
                    continue 2;
                }

                $expected[$id] = null;
            }

            $labels = TableLabelGenerator::generate('A-H', '1-22', $round->seatsPerTable, 'side', 'letters', 'H22');
            $this->messageBus->dispatch(new SetRoundTableLabels($competitionId, $ids, $labels, $expected));
            $say(sprintf('Table names of "%s" and %d later rounds: %s, %s … %s (%d places)', $round->name, count($ids) - 1, $labels[0] ?? '', $labels[1] ?? '', $labels[count($labels) - 1] ?? '', count($labels)));
        }
    }

    /**
     * The organization owning the event (approved at once, the organiser's logo), the extra organisers on its team.
     *
     * @param array{name: string, slug: string, short?: string} $definition
     * @param list<string> $maintainerIds
     * @param \Closure(string): void $say
     */
    private function organization(array $definition, string $competitionId, string $organizerPlayerId, array $maintainerIds, \Closure $say): void
    {
        $organizationId = $this->demoData->organizationIdBySlug($definition['slug']);

        if ($organizationId === null) {
            $organizationId = Uuid::uuid7()->toString();
            $this->messageBus->dispatch(new AddOrganization(
                organizationId: Uuid::fromString($organizationId),
                playerId: $organizerPlayerId,
                name: $definition['name'],
                shortName: $definition['short'] ?? null,
                about: 'A made-up organization on the MySpeedPuzzling sandbox for demonstrating the competition toolkit.',
                website: null,
                socialLinks: [],
                countryCode: null,
                region: null,
                kind: OrganizationKind::Organizer,
                logo: null,
                maintainerIds: $maintainerIds,
                slug: $definition['slug'],
                approve: true,
                // The event's logo, when it has one
                storedLogo: $this->demoData->competitionLogo($competitionId),
            ));
            $say(sprintf('Organization "%s" created', $definition['name']));
        } else {
            $team = $this->demoData->organizationTeam($organizationId);

            foreach ($maintainerIds as $maintainerId) {
                if (!in_array($maintainerId, $team, true)) {
                    $this->messageBus->dispatch(new AddOrganizationMaintainer($organizationId, $maintainerId));
                    $say('Organiser added to the organization\'s team');
                }
            }
        }

        if ($this->demoData->competitionOrganizationId($competitionId) !== $organizationId) {
            $this->messageBus->dispatch(new AssignEventToOrganization(OrganizationItemKind::Competition, $competitionId, $organizationId, $organizerPlayerId));
            $say('Event moved into the organization');
        }
    }

    /**
     * The displays in the event's own look (SandboxDemoDisplays), found again by name - kept as the organisers edit them.
     *
     * @param list<array{name: string, kind: DisplayKind, pinnedRoundId: null|string, design: array<string, mixed>, followRoundIds?: list<string>}> $displays
     * @param \Closure(string): void $say
     */
    private function brandedDisplays(string $competitionId, array $displays, string $organizerPlayerId, \Closure $say): void
    {
        $existing = $this->demoData->displays($competitionId);

        foreach ($displays as $display) {
            if (isset($existing[$display['name']])) {
                continue;
            }

            $displayId = Uuid::uuid7();
            $this->messageBus->dispatch(new AddCompetitionDisplay($displayId, $competitionId, $display['kind'], $display['name'], 'en', $organizerPlayerId, DisplayPreset::Blank));

            if ($display['pinnedRoundId'] !== null) {
                $this->messageBus->dispatch(new ChangeCompetitionDisplayFollow($displayId->toString(), DisplayFollowMode::Pinned, $display['pinnedRoundId']));
            } elseif (($display['followRoundIds'] ?? []) !== []) {
                $this->messageBus->dispatch(new ChangeCompetitionDisplayFollow($displayId->toString(), DisplayFollowMode::Current, null, $display['followRoundIds']));
            }

            $this->messageBus->dispatch(new SaveCompetitionDisplayDesign(
                displayId: $displayId->toString(),
                design: json_decode(json_encode($display['design'], JSON_THROW_ON_ERROR), false, flags: JSON_THROW_ON_ERROR),
                baseVersion: 1,
                // Live results on screen are the organiser's explicit choice - this is it
                liveConfirmed: true,
            ));
            $say(sprintf('Display "%s" added', $display['name']));
        }
    }

    /**
     * A copy of the logo file the handlers may optimise in place - the organiser's own file stays as it is.
     */
    private static function logoUpload(null|string $logoPath): null|UploadedFile
    {
        if ($logoPath === null) {
            return null;
        }

        $copy = sys_get_temp_dir() . '/msp-sandbox-logo-' . bin2hex(random_bytes(6)) . '.' . (pathinfo($logoPath, PATHINFO_EXTENSION) ?: 'png');

        if (!copy($logoPath, $copy)) {
            throw new \RuntimeException(sprintf('Cannot read the logo %s', $logoPath));
        }

        return new UploadedFile($copy, basename($logoPath), null, null, test: true);
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
            // Real events L3: the <iframe> page an organiser puts on their own website (the SPWS Wix site)
            $say(sprintf('  Standing embed    %s  (%s)', $url(StandingEmbedController::ROUTE, ['standingId' => strtolower($standing['id'])], false), $name));
        }

        if ($event->organization !== null) {
            // Name plates (table tents) with a time box per challenge of the championship (table-labels-and-plates.md)
            foreach ($this->demoData->standings($competitionId) as $name => $standing) {
                $kind = str_starts_with($name, 'Individual') ? 'people' : 'lineups';
                $say(sprintf('  Name plates       %s?kind=%s&layout=tents&boxes=standing:%s&flags=1  (%s)', $url('competition_name_tags', ['competitionId' => $competitionId]), $kind, strtolower($standing['id']), $name));
            }
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
