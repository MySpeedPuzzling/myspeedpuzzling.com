<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\OfficialResults;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\TableNumbersCopyRefused;
use SpeedPuzzling\Web\Message\CopyRoundTableNumbers;
use SpeedPuzzling\Web\Results\SameTablesCopyTarget;
use SpeedPuzzling\Web\Services\SameTablesCopy;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * "Use these tables in later rounds of this category" (competition toolkit W3, seating.md): the Results Cup's Pairs
 * round (Puzzle Sharks at table 1, Corner Pieces 2, Edge Hunters 3) copied to its Pairs Final.
 */
final class CopyRoundTableNumbersHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;
    private SameTablesCopy $sameTablesCopy;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->sameTablesCopy = self::getContainer()->get(SameTablesCopy::class);
    }

    public function testTheLaterRoundGetsTheSameTablesForTheSamePeople(): void
    {
        $sharks = $this->pair('Sharks again', [OfficialResultsFixture::PARTICIPANT_BEN, OfficialResultsFixture::PARTICIPANT_ANNA], null);
        $edges = $this->pair('Edges again', [OfficialResultsFixture::PARTICIPANT_GINA, OfficialResultsFixture::PARTICIPANT_HUGO], 1);
        // Not a pair of the Pairs round - keeps its table when nobody matched takes it
        $kept = $this->pair('Kept', [OfficialResultsFixture::PARTICIPANT_EVA, OfficialResultsFixture::PARTICIPANT_IVAN], 8);
        // Cara without Dan is not Corner Pieces: no table yet - after the highest
        $newcomer = $this->pair('Newcomer', [OfficialResultsFixture::PARTICIPANT_CARA, OfficialResultsFixture::PARTICIPANT_FILIP], null);

        $target = $this->target(OfficialResultsFixture::ROUND_PAIRS_FINAL);
        self::assertSame(4, $target->entries);
        self::assertSame(2, $target->matched);
        // Sharks again gets 1 (Edges again had it), Edges again 3, Newcomer 9; Kept stays at 8
        self::assertSame(3, $target->changes);
        self::assertSame(3, $this->sameTablesCopy->plan(OfficialResultsFixture::COMPETITION_RESULTS_CUP, OfficialResultsFixture::ROUND_PAIRS)['sourceSeated']);

        $changed = $this->copy([OfficialResultsFixture::ROUND_PAIRS_FINAL => $target->hash]);

        self::assertSame([OfficialResultsFixture::ROUND_PAIRS_FINAL], array_keys($changed));
        self::assertCount(3, $changed[OfficialResultsFixture::ROUND_PAIRS_FINAL]);
        self::assertSame(1, $this->tableNumber($sharks));
        self::assertSame(3, $this->tableNumber($edges));
        self::assertSame(8, $this->tableNumber($kept));
        // After the highest (8)
        self::assertSame(9, $this->tableNumber($newcomer));

        // The source round is untouched
        self::assertSame(1, $this->database->fetchOne('SELECT table_number FROM competition_team WHERE id = :id', ['id' => OfficialResultsFixture::TEAM_SHARKS]));
    }

    public function testATargetChangedSinceTheConfirmRefusesEverything(): void
    {
        $sharks = $this->pair('Sharks again', [OfficialResultsFixture::PARTICIPANT_BEN, OfficialResultsFixture::PARTICIPANT_ANNA], null);
        $hash = $this->target(OfficialResultsFixture::ROUND_PAIRS_FINAL)->hash;

        // Somebody joins the round after the confirm was shown
        $this->pair('Late', [OfficialResultsFixture::PARTICIPANT_EVA, OfficialResultsFixture::PARTICIPANT_IVAN], null);

        $this->assertRefused(
            [OfficialResultsFixture::ROUND_PAIRS_FINAL => $hash],
            [['round' => OfficialResultsFixture::ROUND_PAIRS_FINAL, 'reason' => 'changed_meanwhile']],
        );
        self::assertNull($this->tableNumber($sharks));

        // A table given meanwhile changes the hash as well
        $hash = $this->target(OfficialResultsFixture::ROUND_PAIRS_FINAL)->hash;
        $this->database->update('competition_team', ['table_number' => 40], ['id' => $sharks]);
        $this->assertRefused(
            [OfficialResultsFixture::ROUND_PAIRS_FINAL => $hash],
            [['round' => OfficialResultsFixture::ROUND_PAIRS_FINAL, 'reason' => 'changed_meanwhile']],
        );
        self::assertSame(40, $this->tableNumber($sharks));
    }

    public function testOnlyLaterRoundsOfTheSameKindAndNothingHalfDone(): void
    {
        $sharks = $this->pair('Sharks again', [OfficialResultsFixture::PARTICIPANT_BEN, OfficialResultsFixture::PARTICIPANT_ANNA], null);
        $hash = $this->target(OfficialResultsFixture::ROUND_PAIRS_FINAL)->hash;

        $this->assertRefused(
            [OfficialResultsFixture::ROUND_PAIRS_FINAL => $hash, OfficialResultsFixture::ROUND_FINAL => 'x'],
            [['round' => OfficialResultsFixture::ROUND_FINAL, 'reason' => 'round_not_available']],
        );
        // The good target was not written either
        self::assertNull($this->tableNumber($sharks));

        $this->assertRefused([], [['round' => null, 'reason' => 'no_targets']]);
    }

    /**
     * @param array<string, string> $targets
     * @return array<string, list<string>>
     */
    private function copy(array $targets): array
    {
        $envelope = $this->messageBus->dispatch(new CopyRoundTableNumbers(
            OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            OfficialResultsFixture::ROUND_PAIRS,
            $targets,
        ));

        /** @var array<string, list<string>> $changed */
        $changed = $envelope->last(HandledStamp::class)?->getResult();

        return $changed;
    }

    /**
     * @param array<string, string> $targets
     * @param list<array{round: null|string, reason: string}> $problems
     */
    private function assertRefused(array $targets, array $problems): void
    {
        try {
            $this->copy($targets);
            self::fail('The copy must be refused');
        } catch (TableNumbersCopyRefused $refused) {
            self::assertSame($problems, $refused->problems);
        }
    }

    private function target(string $roundId): SameTablesCopyTarget
    {
        foreach ($this->sameTablesCopy->plan(OfficialResultsFixture::COMPETITION_RESULTS_CUP, OfficialResultsFixture::ROUND_PAIRS)['targets'] as $target) {
            if ($target->roundId === $roundId) {
                return $target;
            }
        }

        self::fail('No target ' . $roundId);
    }

    /**
     * @param list<string> $participantIds
     */
    private function pair(string $name, array $participantIds, null|int $tableNumber): string
    {
        $teamId = Uuid::uuid7()->toString();
        $this->database->insert('competition_team', ['id' => $teamId, 'round_id' => OfficialResultsFixture::ROUND_PAIRS_FINAL, 'name' => $name, 'table_number' => $tableNumber]);

        foreach ($participantIds as $participantId) {
            $this->database->insert('competition_participant_round', [
                'id' => Uuid::uuid7()->toString(),
                'participant_id' => $participantId,
                'round_id' => OfficialResultsFixture::ROUND_PAIRS_FINAL,
                'team_id' => $teamId,
            ]);
        }

        return $teamId;
    }

    private function tableNumber(string $teamId): null|int
    {
        $number = $this->database->fetchOne('SELECT table_number FROM competition_team WHERE id = :id', ['id' => $teamId]);
        assert($number === null || is_int($number));

        return $number;
    }
}
