<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\MessengerMiddleware;

use Closure;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use SpeedPuzzling\Web\Events\CompetitionRoundsChanged;
use SpeedPuzzling\Web\Events\OfficialRoundResultsPublished;
use SpeedPuzzling\Web\Events\PuzzleMergeApproved;
use SpeedPuzzling\Web\Events\SeriesEditionsChanged;
use SpeedPuzzling\Web\Message\AddComparisonSubject;
use SpeedPuzzling\Web\Message\AddCompetitionRound;
use SpeedPuzzling\Web\Message\AddPuzzle;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Message\AddTableRow;
use SpeedPuzzling\Web\Message\ApplyParticipantImport;
use SpeedPuzzling\Web\Message\ApplyParticipantSheetChanges;
use SpeedPuzzling\Web\Message\ApprovePuzzle;
use SpeedPuzzling\Web\Message\ApprovePuzzleChangeRequest;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Message\BackfillCompetitionRoundSlugs;
use SpeedPuzzling\Web\Message\BackfillRoundPuzzleReveals;
use SpeedPuzzling\Web\Message\BackfillRoundTimezones;
use SpeedPuzzling\Web\Message\CancelMembershipSubscription;
use SpeedPuzzling\Web\Message\ChangeCompetitionRegistrationSettings;
use SpeedPuzzling\Web\Message\ChangeRoundPuzzleReveal;
use SpeedPuzzling\Web\Message\ChangeRoundTableNumbersUsage;
use SpeedPuzzling\Web\Message\CheckInParticipant;
use SpeedPuzzling\Web\Message\ClearComparisonLineUp;
use SpeedPuzzling\Web\Message\ConvertCompetitionToSeries;
use SpeedPuzzling\Web\Message\CreateOrganizationFromSeries;
use SpeedPuzzling\Web\Message\DeleteCompetitionRound;
use SpeedPuzzling\Web\Message\DeleteCompetitionSeries;
use SpeedPuzzling\Web\Message\DeletePlayer;
use SpeedPuzzling\Web\Message\EditCompetitionRound;
use SpeedPuzzling\Web\Message\EditPuzzle;
use SpeedPuzzling\Web\Message\EditPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\GenerateTableLayout;
use SpeedPuzzling\Web\Message\JoinCompetition;
use SpeedPuzzling\Web\Message\KeepDuplicateCopy;
use SpeedPuzzling\Web\Message\KeepRoundPuzzleHiddenEverywhere;
use SpeedPuzzling\Web\Message\LeaveCompetition;
use SpeedPuzzling\Web\Message\LinkEanToPuzzle;
use SpeedPuzzling\Web\Message\MarkParticipantPaid;
use SpeedPuzzling\Web\Message\MoveEditionToSeries;
use SpeedPuzzling\Web\Message\MoveRoundToCompetition;
use SpeedPuzzling\Web\Message\PromoteParticipantFromWaitlist;
use SpeedPuzzling\Web\Message\PublishRoundResults;
use SpeedPuzzling\Web\Message\ReconcileRoundResults;
use SpeedPuzzling\Web\Message\RemovePuzzleFromCompetitionRound;
use SpeedPuzzling\Web\Message\ResetRoundStopwatch;
use SpeedPuzzling\Web\Message\RevealRoundPuzzleNow;
use SpeedPuzzling\Web\Message\SetCompetitionRoundPuzzles;
use SpeedPuzzling\Web\Message\StartRoundStopwatch;
use SpeedPuzzling\Web\Message\StopRoundStopwatch;
use SpeedPuzzling\Web\Message\UndoAutoRemoval;
use SpeedPuzzling\Web\Message\UndoParticipantCheckIn;
use SpeedPuzzling\Web\Message\UnmarkParticipantPaid;
use SpeedPuzzling\Web\Message\UnpublishCompetition;
use SpeedPuzzling\Web\Message\UnpublishRoundResults;
use SpeedPuzzling\Web\Message\UpdateMembershipSubscription;
use SpeedPuzzling\Web\Message\UpdateWjpcPlayerId;
use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\ComparisonKind;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\ParticipantImportRows;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\PuzzleRecordValues;
use SplFileInfo;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class SerializedByLockMessagesTest extends TestCase
{
    /**
     * TerminateMembershipDueToDisputeHandler still locks inside the handler (its subscription id
     * is only known after asking Stripe) - it stays mutually exclusive with these two only while
     * all three use the very same key.
     */
    public function testMembershipMessagesLockTheSubscriptionUnderTheSameKey(): void
    {
        self::assertSame('stripe-subscription-sub_123', (new UpdateMembershipSubscription('sub_123'))->lockKey());
        self::assertSame('stripe-subscription-sub_123', (new CancelMembershipSubscription('sub_123'))->lockKey());
    }

    /**
     * Every add of one owner waits for the previous one to commit, whatever it adds - the cap is counted per owner
     */
    public function testComparisonAddsLockTheOwnersLineUps(): void
    {
        $owner = '018d0000-0000-0000-0000-00000000000A';
        $key = (new AddComparisonSubject($owner, 'p-018d0000-0000-0000-0000-00000000000b'))->lockKey();

        self::assertSame('comparison-line-up-018d0000-0000-0000-0000-00000000000a', $key);
        self::assertSame($key, (new AddComparisonSubject(strtolower($owner), 't-018d0000-0000-0000-0000-00000000000c', '018d0000-0000-0000-0000-00000000000d'))->lockKey());
        self::assertNotSame($key, (new AddComparisonSubject('018d0000-0000-0000-0000-00000000000e', 'p-018d0000-0000-0000-0000-00000000000b'))->lockKey());

        // "Clear" waits for the adds of the same owner and they for it - an add never lands half way through a clear
        self::assertSame($key, (new ClearComparisonLineUp($owner, ComparisonKind::Pairs))->lockKey());
    }

    /**
     * Every message that changes a puzzle's record waits for the others on the same puzzle - the record version check
     * (PuzzleRecordVersion) runs inside the transaction, so two saves must not check at the same moment
     */
    public function testEveryChangeOfAPuzzleRecordLocksThePuzzle(): void
    {
        $puzzleId = '018D0003-0000-0000-0000-000000000001';
        $key = 'puzzle-018d0003-0000-0000-0000-000000000001';
        $values = new PuzzleRecordValues(name: 'Puzzle', nameLanguage: null, alternativeNames: new PuzzleNames(), manufacturerId: null, piecesCount: 500, eans: EanList::fromStored(null), brandCodes: BrandCodeList::fromStored(null));

        self::assertSame($key, (new EditPuzzle($puzzleId, 'editor', $values))->lockKey());
        self::assertSame($key, (new ApprovePuzzle($puzzleId, 'reviewer', 'Puzzle', null, new PuzzleNames(), 500, EanList::fromStored(null), BrandCodeList::fromStored(null)))->lockKey());
        self::assertSame($key, (new ApprovePuzzleChangeRequest('change-request', $puzzleId, 'reviewer'))->lockKey());
        self::assertSame($key, (new ApprovePuzzleMergeRequest('merge-request', 'reviewer', $puzzleId, 'Puzzle', null, null, 500, null, null))->lockKey());
        self::assertSame($key, (new LinkEanToPuzzle($puzzleId, 'player', '4005556147090'))->lockKey());
        self::assertSame($key, (new AddPuzzle(
            Uuid::fromString($puzzleId),
            'player',
            'Puzzle',
            'Brand',
            500,
            new UploadedFile(__FILE__, 'box.jpg', test: true),
            EanList::fromStored(null),
            BrandCodeList::fromStored(null),
        ))->lockKey());
    }

    /**
     * Every write to an event's participants takes turns with the others under the import's key
     * (docs/features/competitions-management/registration.md, participants-spreadsheet.md D13): two registrations
     * never both take the last spot, an import never plans over a registration that is half way through.
     */
    public function testEveryWriteToAnEventsParticipantsLocksTheEventUnderTheImportsKey(): void
    {
        $competitionId = '018D0004-0000-0000-0000-000000000002';
        $participantId = '018d0006-0000-0000-0000-000000000001';
        $key = (new ApplyParticipantImport($competitionId, new ParticipantImportRows([]), 'update', 'fingerprint'))->lockKey();

        self::assertSame('participant-import-018d0004-0000-0000-0000-000000000002', $key);
        self::assertSame($key, CompetitionParticipantsLock::key($competitionId));
        self::assertSame($key, (new JoinCompetition($competitionId, 'player'))->lockKey());
        self::assertSame($key, (new JoinCompetition(strtolower($competitionId), 'player', $participantId))->lockKey());
        self::assertSame($key, (new LeaveCompetition($competitionId, 'player'))->lockKey());
        self::assertSame($key, (new MarkParticipantPaid($competitionId, $participantId))->lockKey());
        self::assertSame($key, (new UnmarkParticipantPaid($competitionId, $participantId))->lockKey());
        self::assertSame($key, (new PromoteParticipantFromWaitlist($competitionId, $participantId))->lockKey());
        self::assertSame($key, (new CheckInParticipant($competitionId, $participantId))->lockKey());
        self::assertSame($key, (new UndoParticipantCheckIn($competitionId, $participantId))->lockKey());
        self::assertSame($key, (new ChangeCompetitionRegistrationSettings($competitionId, true, 10, null, null, 'Europe/Prague', null, null))->lockKey());
        self::assertSame($key, (new ApplyParticipantSheetChanges($competitionId, 'player', null, [], dryRun: true))->lockKey());
        // Back to draft only while nobody joined - a join waits for the check (docs/features/organizations/README.md)
        self::assertSame($key, (new UnpublishCompetition($competitionId))->lockKey());
    }

    /**
     * The restructuring moves take the lock of the event the edition or round is in when the move is asked for
     * (docs/features/organizations/README.md "Restructuring tools", D7) - a registration or a round entry never lands
     * half way through a move.
     */
    public function testTheMovesLockTheEventTheyMoveFrom(): void
    {
        $competitionId = '018D0004-0000-0000-0000-000000000002';
        $key = CompetitionParticipantsLock::key($competitionId);

        self::assertSame($key, (new MoveEditionToSeries($competitionId, 'series', 'player'))->lockKey());
        self::assertSame($key, (new MoveRoundToCompetition('round', $competitionId, 'target', 'player'))->lockKey());
        // Turning the event into a series may delete its participants (dropParticipants) - the ones it checked
        // (docs/features/events-page/high-frequency-series.md P11)
        self::assertSame($key, (new ConvertCompetitionToSeries($competitionId, Uuid::uuid7(), keepAsEdition: false, dropParticipants: true))->lockKey());
    }

    /**
     * The guard behind the test above (review 2, A-F2 / B-M1): a message whose handler touches an event's participants,
     * round entries or pairs/teams - or deletes or changes a round, which can take official results with it - must take
     * the event's participants lock (SerializedByLock with CompetitionParticipantsLock::key($this->competitionId)), or
     * be listed in NOT_LOCKED with the reason it does not need to.
     *
     * "Touches" is read from the handler's source: it names CompetitionParticipant, CompetitionParticipantRound,
     * CompetitionTeam or CompetitionRound (the entity or its repository), writes one of their tables in SQL, or uses a
     * service under src/Services that does (followed through the services that use such a service). A new handler of
     * that kind fails here until its message takes the lock or gets a reason - forgetting is not an option.
     */
    public function testEveryMessageTouchingAnEventsParticipantsTakesTheEventsLock(): void
    {
        $touching = self::messagesTouchingParticipants();

        // The detection itself works - these are known writers
        foreach ([ApplyParticipantImport::class, JoinCompetition::class, MarkParticipantPaid::class, LeaveCompetition::class, DeleteCompetitionRound::class, EditCompetitionRound::class] as $known) {
            self::assertArrayHasKey($known, $touching, $known . ' must be detected as touching the participants');
        }

        $problems = [];

        foreach ($touching as $messageClass => $handlerClass) {
            if (isset(self::NOT_LOCKED[$messageClass])) {
                continue;
            }

            $problem = self::lockProblem($messageClass);

            if ($problem !== null) {
                $problems[] = sprintf('%s (handled by %s): %s', $messageClass, $handlerClass, $problem);
            }
        }

        self::assertSame([], $problems, 'Take CompetitionParticipantsLock in these messages, or list them in NOT_LOCKED with a reason');

        // Every reason is about a message that still touches the participants - no stale entries
        foreach (array_keys(self::NOT_LOCKED) as $messageClass) {
            self::assertArrayHasKey($messageClass, $touching, $messageClass . ' is listed in NOT_LOCKED but no longer touches the participants - remove it');
            self::assertNotNull(self::lockProblem($messageClass), $messageClass . ' takes the lock - remove it from NOT_LOCKED');
        }
    }

    /**
     * Messages whose handler touches the participant model and that deliberately do not take the event's lock.
     */
    private const array NOT_LOCKED = [
        AddCompetitionRound::class => 'creates an empty round - nobody is entered, nothing official exists yet',
        AddPuzzleSolvingTime::class => 'a player\'s own time - reads the round to link the time, never writes entries or official results',
        AddPuzzleToCompetitionRound::class => 'adds a puzzle to a round - entries and results are not touched',
        AddTableRow::class => 'the table layout (rows and spots), not round entries',
        GenerateTableLayout::class => 'the table layout (rows and spots), not round entries',
        BackfillCompetitionRoundSlugs::class => 'console backfill of round slugs - no entry, no result',
        BackfillRoundTimezones::class => 'console backfill of round time zones - no entry, no result',
        ChangeRoundTableNumbersUsage::class => 'a display switch of the round - the table numbers stay on the entries as they are',
        CreateOrganizationFromSeries::class => 'turns a series into an organization: writes the organization, follows and redirect rows for the old addresses of every edition and round (EventUrlRedirects reads their slugs) - several events in one message, and no spot, entry or result changes',
        DeleteCompetitionSeries::class => 'deletes whole editions with everything they hold - several events in one message (one lock key per message), and nothing of theirs is meant to stay',
        DeletePlayer::class => 'account deletion only clears the player link on the rows of every event the player was in - an unbounded set of events; no spot, entry or result changes',
        OfficialRoundResultsPublished::class => 'the notification after a publish - reads only',
        PublishRoundResults::class => 'shows the recorded results on the public page - writes only the round\'s published state, nothing recorded changes',
        UnpublishRoundResults::class => 'takes the results off the public page - writes only the round\'s published state, nothing recorded changes',
        ResetRoundStopwatch::class => 'the round\'s stopwatch only',
        StartRoundStopwatch::class => 'the round\'s stopwatch only',
        StopRoundStopwatch::class => 'the round\'s stopwatch only',
        SetCompetitionRoundPuzzles::class => 'the round\'s puzzles - entries and results are not touched',
        // Players' own times and the round they belong to (round-results.md) - puzzle_solving_time only
        CompetitionRoundsChanged::class => 're-links players\' own times to rounds (puzzle_solving_time) - never entries or official results',
        PuzzleMergeApproved::class => 're-links players\' own times to rounds (puzzle_solving_time) - never entries or official results',
        ReconcileRoundResults::class => 'console reconcile of players\' own times and their rounds (puzzle_solving_time) - never entries or official results',
        SeriesEditionsChanged::class => 're-matches players\' series picks to editions and their rounds (puzzle_solving_time) - never entries or official results',
        EditPuzzleSolvingTime::class => 'a player\'s own time - reads the rounds to link it, never writes entries or official results',
        KeepDuplicateCopy::class => 'a player\'s own times (duplicate results) - reads the rounds to link them, never writes entries or official results',
        UndoAutoRemoval::class => 'a player\'s own time restored (duplicate results) - reads the rounds to link it, never writes entries or official results',
        RemovePuzzleFromCompetitionRound::class => 'removes a puzzle from a round - entries and results are not touched',
        ApprovePuzzleMergeRequest::class => 'merges puzzles - moves round puzzles and players\' own times between puzzles of any event (no single event to lock); never entries, teams or official results',
        BackfillRoundPuzzleReveals::class => 'console backfill of round puzzles\' reveal - SecretPuzzleHides takes its own row locks; no entry, no result',
        ChangeRoundPuzzleReveal::class => 'a round puzzle\' reveal - SecretPuzzleHides takes its own row locks; entries and results are not touched',
        KeepRoundPuzzleHiddenEverywhere::class => 'a round puzzle\' site-wide hide - SecretPuzzleHides takes its own row locks; entries and results are not touched',
        RevealRoundPuzzleNow::class => 'a round puzzle\' reveal - SecretPuzzleHides takes its own row locks; entries and results are not touched',
        UpdateWjpcPlayerId::class => 'writes only remote_id, a column nothing else writes; its handler waits for worldjigsawpuzzle.org, and the event\'s lock held across that call would hold up every registration',
    ];

    private const string PARTICIPANT_MODEL = '/\\b(CompetitionParticipant|CompetitionParticipantRound|CompetitionTeam|CompetitionRound)(Repository)?\\b'
        . '|\\b(DELETE\\s+FROM|UPDATE|INSERT\\s+INTO)\\s+competition_(participant|participant_round|team|round)\\b/i';

    /**
     * Whether the message takes the event's participants lock - null when it does, the reason otherwise.
     *
     * @param class-string $messageClass
     */
    private static function lockProblem(string $messageClass): null|string
    {
        $class = new ReflectionClass($messageClass);

        if ($class->implementsInterface(SerializedByLock::class) === false) {
            return 'does not implement SerializedByLock';
        }

        if ($class->hasProperty('competitionId') === false) {
            return 'has no competitionId to lock the event by';
        }

        // Only competitionId is set - lockKey() must not need anything else
        $message = $class->newInstanceWithoutConstructor();
        $competitionId = Uuid::uuid7()->toString();
        Closure::bind(static function (object $message) use ($competitionId): void {
            /** @phpstan-ignore property.notFound */
            $message->competitionId = $competitionId;
        }, null, $messageClass)($message);

        assert($message instanceof SerializedByLock);

        if ($message->lockKey() !== CompetitionParticipantsLock::key($competitionId)) {
            return 'locks under another key than CompetitionParticipantsLock::key($this->competitionId)';
        }

        return null;
    }

    /**
     * @return array<class-string, class-string> message class => handler class
     */
    private static function messagesTouchingParticipants(): array
    {
        $root = dirname(__DIR__, 3) . '/src/';
        $writerServices = self::writerServices($root . 'Services');
        $touching = [];

        foreach (self::phpFiles($root . 'MessageHandler') as $file) {
            [$handlerClass, $namespace, $source] = self::read($file);

            if (!class_exists($handlerClass) || !method_exists($handlerClass, '__invoke')) {
                continue;
            }

            $type = (new ReflectionMethod($handlerClass, '__invoke'))->getParameters()[0]->getType();

            if (!$type instanceof ReflectionNamedType || !class_exists($type->getName())) {
                continue;
            }

            if (preg_match(self::PARTICIPANT_MODEL, $source) === 1 || self::references($namespace, $source, $writerServices) !== []) {
                /** @var class-string $messageClass */
                $messageClass = $type->getName();
                $touching[$messageClass] = $handlerClass;
            }
        }

        ksort($touching);

        return $touching;
    }

    /**
     * Services that write the participant model, and the services using them.
     *
     * @return list<string>
     */
    private static function writerServices(string $directory): array
    {
        $services = [];

        foreach (self::phpFiles($directory) as $file) {
            [$class, $namespace, $source] = self::read($file);
            $services[$class] = [$namespace, $source];
        }

        $writers = array_keys(array_filter(
            $services,
            static fn (array $service): bool => preg_match(self::PARTICIPANT_MODEL, $service[1]) === 1,
        ));

        do {
            $added = false;

            foreach ($services as $class => [$namespace, $source]) {
                if (!in_array($class, $writers, true) && self::references($namespace, $source, $writers) !== []) {
                    $writers[] = $class;
                    $added = true;
                }
            }
        } while ($added);

        return $writers;
    }

    /**
     * @param list<string> $classes
     * @return list<string> those of $classes the source uses (imported, or by its short name in the same namespace)
     */
    private static function references(string $namespace, string $source, array $classes): array
    {
        preg_match_all('/^use ([^;]+);/m', $source, $matches);
        $imports = $matches[1];

        return array_values(array_filter($classes, static function (string $class) use ($namespace, $source, $imports): bool {
            $separator = strrpos($class, '\\');
            assert($separator !== false);

            return in_array($class, $imports, true)
                || (substr($class, 0, $separator) === $namespace && preg_match('/\\b' . preg_quote(substr($class, $separator + 1), '/') . '\\b/', $source) === 1);
        }));
    }

    /**
     * @return array{string, string, string} class, namespace, source
     */
    private static function read(string $file): array
    {
        $source = (string) file_get_contents($file);
        preg_match('/^namespace ([^;]+);/m', $source, $namespace);
        assert(isset($namespace[1]));

        return [$namespace[1] . '\\' . basename($file, '.php'), $namespace[1], $source];
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $directory): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            assert($file instanceof SplFileInfo);

            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
