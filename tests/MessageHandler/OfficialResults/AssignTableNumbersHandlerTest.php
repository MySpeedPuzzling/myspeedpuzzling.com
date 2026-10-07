<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\OfficialResults;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\InvalidTableNumbers;
use SpeedPuzzling\Web\Message\AssignTableNumbers;
use SpeedPuzzling\Web\Message\ChangeRoundTableNumbersUsage;
use SpeedPuzzling\Web\Query\GetRoundResultsOverview;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class AssignTableNumbersHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testRenumbersInOneWriteAndAnswersWhatChanged(): void
    {
        $changed = $this->assign([
            ['entry' => 'participant_round:' . OfficialResultsFixture::ENTRY_A_ANNA, 'number' => 2],
            ['entry' => 'participant_round:' . OfficialResultsFixture::ENTRY_A_BEN, 'number' => 1],
            ['entry' => 'participant_round:' . OfficialResultsFixture::ENTRY_A_FILIP, 'number' => 6],
            // Unchanged - not reported
            ['entry' => 'participant_round:' . OfficialResultsFixture::ENTRY_A_CARA, 'number' => 3],
        ]);

        self::assertSame([
            'participant_round:' . OfficialResultsFixture::ENTRY_A_ANNA,
            'participant_round:' . OfficialResultsFixture::ENTRY_A_BEN,
            'participant_round:' . OfficialResultsFixture::ENTRY_A_FILIP,
        ], self::sorted($changed));
        self::assertSame(2, $this->tableNumber(OfficialResultsFixture::ENTRY_A_ANNA));
        self::assertSame(1, $this->tableNumber(OfficialResultsFixture::ENTRY_A_BEN));
        self::assertSame(6, $this->tableNumber(OfficialResultsFixture::ENTRY_A_FILIP));

        $overview = self::getContainer()->get(GetRoundResultsOverview::class)->forRound(OfficialResultsFixture::ROUND_GROUP_A);
        self::assertSame(6, $overview->entriesTotal);
        self::assertSame(6, $overview->entriesWithTableNumber);
        self::assertTrue($overview->isSeated());
    }

    public function testAnythingWrongRefusesEverything(): void
    {
        try {
            $this->assign([
                ['entry' => 'participant_round:' . OfficialResultsFixture::ENTRY_A_FILIP, 'number' => 9],
                // Ben keeps 2, so Anna can not have it
                ['entry' => 'participant_round:' . OfficialResultsFixture::ENTRY_A_ANNA, 'number' => 2],
                ['entry' => 'participant_round:' . OfficialResultsFixture::ENTRY_B_GINA, 'number' => 7],
                ['entry' => 'participant_round:' . OfficialResultsFixture::ENTRY_A_DAN, 'number' => 0],
            ]);
            self::fail('The table numbers must be refused.');
        } catch (InvalidTableNumbers $invalid) {
            self::assertSame([
                ['entry' => 'participant_round:' . OfficialResultsFixture::ENTRY_B_GINA, 'reason' => 'entry_not_found'],
                ['entry' => 'participant_round:' . OfficialResultsFixture::ENTRY_A_DAN, 'reason' => 'invalid_table_number'],
                ['entry' => 'participant_round:' . OfficialResultsFixture::ENTRY_A_ANNA, 'reason' => 'table_number_taken'],
            ], $invalid->problems);
        }

        self::assertNull($this->tableNumber(OfficialResultsFixture::ENTRY_A_FILIP));
    }

    public function testTheRoundCanDoWithoutTableNumbers(): void
    {
        $this->messageBus->dispatch(new ChangeRoundTableNumbersUsage(OfficialResultsFixture::COMPETITION_RESULTS_CUP, OfficialResultsFixture::ROUND_GROUP_B, true));

        $overview = self::getContainer()->get(GetRoundResultsOverview::class)->forRound(OfficialResultsFixture::ROUND_GROUP_B);
        self::assertTrue($overview->tableNumbersOff);
        self::assertTrue($overview->isSeated());

        $this->messageBus->dispatch(new ChangeRoundTableNumbersUsage(OfficialResultsFixture::COMPETITION_RESULTS_CUP, OfficialResultsFixture::ROUND_GROUP_B, false));
        self::assertFalse(self::getContainer()->get(GetRoundResultsOverview::class)->forRound(OfficialResultsFixture::ROUND_GROUP_B)->tableNumbersOff);
    }

    /**
     * @param list<array{entry: string, number: null|int}> $assignments
     * @return list<string>
     */
    private function assign(array $assignments): array
    {
        $envelope = $this->messageBus->dispatch(new AssignTableNumbers(
            OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            OfficialResultsFixture::ROUND_GROUP_A,
            $assignments,
        ));

        /** @var list<string> $changed */
        $changed = $envelope->last(HandledStamp::class)?->getResult();

        return $changed;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    private function tableNumber(string $entryId): null|int
    {
        $number = $this->database->fetchOne('SELECT table_number FROM competition_participant_round WHERE id = :id', ['id' => $entryId]);
        assert($number === null || is_int($number));

        return $number;
    }
}
