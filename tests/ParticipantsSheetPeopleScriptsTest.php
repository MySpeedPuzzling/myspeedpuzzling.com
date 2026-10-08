<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The People tab's browser modules (stream E of the participants spreadsheet), executed by node through
 * tests/participants-sheet-people-harness.mjs - the suites under tests/participants-sheet-people/ (node:assert) and the
 * facts worth reading here:
 * - paste: adding people by pasting names (`name`, `name ⇥ country`, `name ⇥ country ⇥ external id`), matched by the
 *   name key against the active people ("already on the list") and the removed ones ("restore?"), duplicates within the
 *   paste, header lines, refusals the server would make;
 * - registration: the actions each registration state allows, the waitlist order (FIFO by registeredAt, id), the
 *   counters and the first-in-line hint, the endpoint call, merging its answer (never the version);
 * - filters: the People filters and the search, rows held while focused or open in the editor, the Columns menu's
 *   storage, team labels (O1), the registration summary.
 */
final class ParticipantsSheetPeopleScriptsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function suites(): iterable
    {
        foreach (['paste', 'registration', 'filters'] as $suite) {
            yield $suite => [$suite];
        }
    }

    #[DataProvider('suites')]
    public function testSuitePasses(string $suite): void
    {
        [$result] = $this->runInNode([['suite' => $suite]]);

        self::assertIsArray($result);
        self::assertArrayHasKey('failures', $result);
        self::assertArrayHasKey('passed', $result);

        $failures = $result['failures'];
        self::assertIsArray($failures);
        self::assertSame([], $failures, $suite . ": \n" . json_encode($failures, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        self::assertGreaterThan(5, $result['passed'], 'the suite ran its tests');
    }

    public function testNamesPastedFromExcelBecomeNewPeopleOnceAndNamesOnTheListAreLeftOut(): void
    {
        [$paste] = $this->runInNode([[
            'fn' => 'namePaste',
            'state' => self::state(),
            // Excel: CRLF, a header, a name already on the list spelled differently, a removed one, a duplicate
            'text' => "Name\tCountry\r\nRobin Sampler\tCZ\r\n  kim   EXAMPLE\r\nOla Fictive\r\nDana Mock\tUnited States\tWJPF-9\r\nrobin sampler\r\n",
        ]]);

        self::assertIsArray($paste);
        self::assertSame([
            ['name' => 'Name', 'status' => 'header', 'country' => null, 'externalId' => null, 'matches' => []],
            ['name' => 'Robin Sampler', 'status' => 'new', 'country' => 'cz', 'externalId' => null, 'matches' => []],
            ['name' => 'kim EXAMPLE', 'status' => 'existing', 'country' => null, 'externalId' => null, 'matches' => ['p-kim']],
            ['name' => 'Ola Fictive', 'status' => 'removed', 'country' => null, 'externalId' => null, 'matches' => ['p-ola']],
            ['name' => 'Dana Mock', 'status' => 'new', 'country' => 'us', 'externalId' => 'WJPF-9', 'matches' => []],
            ['name' => 'robin sampler', 'status' => 'duplicate', 'country' => null, 'externalId' => null, 'matches' => []],
        ], $paste['lines']);
        self::assertSame(['new' => 2, 'existing' => 1, 'removed' => 1, 'duplicate' => 1, 'invalid' => 0, 'header' => 1], $paste['counts']);
        // One group per new person - one refused name never blocks the others; the undo removes them again
        self::assertSame([
            ['id' => 'id3', 'changes' => [['op' => 'newParticipant', 'id' => 'id1', 'name' => 'Robin Sampler', 'country' => 'cz', 'externalId' => null]]],
            ['id' => 'id4', 'changes' => [['op' => 'newParticipant', 'id' => 'id2', 'name' => 'Dana Mock', 'country' => 'us', 'externalId' => 'WJPF-9']]],
        ], $paste['groups']);
        self::assertSame([
            ['id' => 'id6', 'changes' => [['op' => 'remove', 'participant' => 'id2']]],
            ['id' => 'id5', 'changes' => [['op' => 'remove', 'participant' => 'id1']]],
        ], $paste['inverse']);
    }

    public function testTickingTheRemovedPersonRestoresThemInsteadOfAddingAnother(): void
    {
        [$paste] = $this->runInNode([[
            'fn' => 'namePaste',
            'state' => self::state(),
            'text' => "Ola Fictive\n",
            'ticks' => ['l0' => true],
        ]]);

        self::assertIsArray($paste);
        self::assertSame([['id' => 'id1', 'changes' => [['op' => 'restore', 'participant' => 'p-ola']]]], $paste['groups']);
        self::assertSame([['id' => 'id2', 'changes' => [['op' => 'remove', 'participant' => 'p-ola']]]], $paste['inverse']);
    }

    public function testRegistrationActionsCountersAndTheFirstInLine(): void
    {
        $registration = static fn (string $status, null|string $registeredAt = null, null|string $checkedInAt = null): array => [
            'status' => $status, 'registeredAt' => $registeredAt, 'paidAt' => $status === 'paid' ? '2026-09-01T10:00:00+00:00' : null, 'checkedInAt' => $checkedInAt,
        ];
        $person = static fn (string $id, array $registration, null|string $removedAt = null): array => ['id' => $id, 'name' => $id, 'removedAt' => $removedAt, 'registration' => $registration];

        [$inPerson, $online] = $this->runInNode([
            [
                'fn' => 'registration',
                'capacity' => 3,
                'people' => [
                    $person('a', $registration('reserved')),
                    $person('b', $registration('paid', null, '2026-10-08T08:00:00+00:00')),
                    $person('c', $registration('waitlisted', '2026-09-02T10:00:00+00:00')),
                    $person('d', $registration('waitlisted', '2026-09-01T10:00:00+00:00')),
                    $person('e', $registration('paid'), '2026-09-05T10:00:00+00:00'),
                ],
            ],
            [
                'fn' => 'registration',
                'checkIn' => false,
                'people' => [$person('a', $registration('reserved', null, '2026-10-08T08:00:00+00:00'))],
            ],
        ]);

        self::assertIsArray($inPerson);
        self::assertSame([
            'a' => ['markPaid', 'checkIn'],
            'b' => ['unmarkPaid', 'undoCheckIn'],
            'c' => ['promote', 'promoteAndMarkPaid'],
            'd' => ['promote', 'promoteAndMarkPaid'],
            'e' => [],
        ], $inPerson['actions']);
        self::assertSame(['d' => 1, 'c' => 2], $inPerson['positions']);
        self::assertSame(['reserved' => 1, 'paid' => 1, 'waitlisted' => 2, 'taken' => 2, 'checkedIn' => 1, 'capacity' => 3, 'free' => 1, 'over' => false], $inPerson['counts']);
        self::assertSame('d', $inPerson['first']);

        // Online: nobody walks in - no check-in and no undo of one
        self::assertIsArray($online);
        self::assertSame(['a' => ['markPaid']], $online['actions']);
        self::assertNull($online['first']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function state(): array
    {
        $person = static fn (string $id, string $name, null|string $removedAt = null): array => ['id' => $id, 'name' => $name, 'country' => null, 'removedAt' => $removedAt, 'player' => null, 'playerResultRounds' => []];

        return [
            'version' => 'v1',
            'competition' => ['id' => 'c1', 'registrationManaged' => false],
            'rounds' => [],
            'people' => [$person('p-kim', 'Kim Example'), $person('p-ola', 'Ola Fictive', '2026-10-01T10:00:00+00:00'), $person('p-pat', 'Pat Sample')],
            'places' => [],
            'teams' => [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $cases
     * @return list<mixed>
     */
    private function runInNode(array $cases): array
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/participants-sheet-people-harness.mjs']);
        $process->setInput((string) json_encode($cases, JSON_THROW_ON_ERROR));
        $process->setTimeout(120);
        $process->mustRun();

        /** @var list<mixed> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $results;
    }
}
