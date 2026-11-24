<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\OfficialResults;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\InvalidTableLabels;
use SpeedPuzzling\Web\Exceptions\InvalidTableNumbers;
use SpeedPuzzling\Web\Exceptions\TableLabelsRefused;
use SpeedPuzzling\Web\Message\AssignTableNumbers;
use SpeedPuzzling\Web\Message\SetRoundTableLabels;
use SpeedPuzzling\Web\Query\GetRoundResultsOverview;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Table names (table-labels-and-plates.md): the Results Cup's Pairs round seats Puzzle Sharks at table 1, Corner
 * Pieces at 2 and Edge Hunters at 3.
 */
final class SetRoundTableLabelsHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testTheSameListForSeveralRoundsAndBackToNumbers(): void
    {
        $labels = ['A1 L', 'A1 R', 'A2 L', 'A2 R'];
        $changed = $this->set([OfficialResultsFixture::ROUND_PAIRS, OfficialResultsFixture::ROUND_PAIRS_FINAL], $labels);

        self::assertSame([OfficialResultsFixture::ROUND_PAIRS, OfficialResultsFixture::ROUND_PAIRS_FINAL], $changed);

        $overview = self::getContainer()->get(GetRoundResultsOverview::class)->forRound(OfficialResultsFixture::ROUND_PAIRS);
        self::assertSame($labels, $overview->jsonSerialize()['tableLabels'] ?? null);
        self::assertSame('A2 L', $overview->tableLabels?->labelOf(3));
        // Nobody moved
        self::assertSame(1, $this->database->fetchOne('SELECT table_number FROM competition_team WHERE id = :id', ['id' => OfficialResultsFixture::TEAM_SHARKS]));

        // Another round keeps plain numbers - no key in its JSON
        $other = self::getContainer()->get(GetRoundResultsOverview::class)->forRound(OfficialResultsFixture::ROUND_FINAL);
        self::assertArrayNotHasKey('tableLabels', $other->jsonSerialize());

        // Saving the same list again changes nothing; null = plain numbers again
        self::assertSame([], $this->set([OfficialResultsFixture::ROUND_PAIRS], $labels, [OfficialResultsFixture::ROUND_PAIRS => $labels]));
        self::assertSame([OfficialResultsFixture::ROUND_PAIRS], $this->set([OfficialResultsFixture::ROUND_PAIRS], null, [OfficialResultsFixture::ROUND_PAIRS => $labels]));
        self::assertNull($this->database->fetchOne('SELECT table_labels FROM competition_round WHERE id = :id', ['id' => OfficialResultsFixture::ROUND_PAIRS]));
    }

    public function testAListShorterThanTheSeatedTablesIsRefused(): void
    {
        $this->assertRefused(
            fn () => $this->set([OfficialResultsFixture::ROUND_PAIRS], ['A1', 'A2']),
            [['round' => OfficialResultsFixture::ROUND_PAIRS, 'reason' => 'too_few', 'highest' => 3]],
        );
        self::assertNull($this->database->fetchOne('SELECT table_labels FROM competition_round WHERE id = :id', ['id' => OfficialResultsFixture::ROUND_PAIRS]));
    }

    public function testAListChangedMeanwhileRefusesEveryRound(): void
    {
        $this->set([OfficialResultsFixture::ROUND_PAIRS_FINAL], ['B1', 'B2']);

        // The editor still showed plain numbers for the final
        $this->assertRefused(
            fn () => $this->set([OfficialResultsFixture::ROUND_PAIRS, OfficialResultsFixture::ROUND_PAIRS_FINAL], ['A1', 'A2', 'A3']),
            [['round' => OfficialResultsFixture::ROUND_PAIRS_FINAL, 'reason' => 'changed_meanwhile', 'current' => ['B1', 'B2']]],
        );
        // The Pairs round was not written either
        self::assertNull($this->database->fetchOne('SELECT table_labels FROM competition_round WHERE id = :id', ['id' => OfficialResultsFixture::ROUND_PAIRS]));
    }

    public function testInvalidLabelsAreRefused(): void
    {
        try {
            $this->set([OfficialResultsFixture::ROUND_PAIRS], ['A1-1', 'a1 1', 'A2', '<b>']);
            self::fail('Refused');
        } catch (InvalidTableLabels $invalid) {
            self::assertSame([
                ['label' => 'a1 1', 'reason' => 'duplicate', 'first' => 'A1-1'],
                ['label' => '<b>', 'reason' => 'characters'],
            ], $invalid->problems);
        }
    }

    public function testATableBeyondTheListIsRefusedWhenSeating(): void
    {
        $this->set([OfficialResultsFixture::ROUND_PAIRS], ['A1', 'A2', 'A3']);

        try {
            $this->messageBus->dispatch(new AssignTableNumbers(OfficialResultsFixture::COMPETITION_RESULTS_CUP, OfficialResultsFixture::ROUND_PAIRS, [
                ['entry' => 'team:' . OfficialResultsFixture::TEAM_SHARKS, 'from' => 1, 'number' => 4],
            ]));
            self::fail('Refused');
        } catch (InvalidTableNumbers $invalid) {
            self::assertSame('no_label', $invalid->problems[0]['reason']);
        }

        self::assertSame(1, $this->database->fetchOne('SELECT table_number FROM competition_team WHERE id = :id', ['id' => OfficialResultsFixture::TEAM_SHARKS]));
    }

    /**
     * @param list<string> $roundIds
     * @param null|list<string> $labels
     * @param array<string, null|list<string>> $expected
     * @return list<string>
     */
    private function set(array $roundIds, null|array $labels, array $expected = []): array
    {
        $envelope = $this->messageBus->dispatch(new SetRoundTableLabels(OfficialResultsFixture::COMPETITION_RESULTS_CUP, $roundIds, $labels, $expected));

        /** @var list<string> $changed */
        $changed = $envelope->last(HandledStamp::class)?->getResult();

        return $changed;
    }

    /**
     * @param list<array<string, mixed>> $problems
     */
    private function assertRefused(callable $call, array $problems): void
    {
        try {
            $call();
            self::fail('Refused');
        } catch (TableLabelsRefused $refused) {
            self::assertSame($problems, $refused->problems);
        }
    }
}
