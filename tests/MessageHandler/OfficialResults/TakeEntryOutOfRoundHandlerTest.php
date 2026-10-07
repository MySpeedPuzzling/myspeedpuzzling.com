<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\OfficialResults;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\OfficialResultsProtected;
use SpeedPuzzling\Web\Exceptions\RoundEntryNotFound;
use SpeedPuzzling\Web\Message\TakeEntryOutOfRound;
use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * "Take out of this round" on the results desk (review2-business MINOR-9): a mistaken advance is undone without the
 * participants or teams page - never for an entry with a result or a qualified mark.
 */
final class TakeEntryOutOfRoundHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testAPersonWithoutDataLeavesTheRoundAndStaysInTheEvent(): void
    {
        $this->takeOut(OfficialResultsFixture::ROUND_FINAL, 'participant_round:' . OfficialResultsFixture::ENTRY_FINAL_ANNA);

        self::assertFalse($this->database->fetchOne('SELECT 1 FROM competition_participant_round WHERE id = :id', ['id' => OfficialResultsFixture::ENTRY_FINAL_ANNA]));
        // Her Group A entry and her place in the event stay
        self::assertNotFalse($this->database->fetchOne('SELECT 1 FROM competition_participant_round WHERE id = :id', ['id' => OfficialResultsFixture::ENTRY_A_ANNA]));
        self::assertNull($this->database->fetchOne('SELECT deleted_at FROM competition_participant WHERE id = :id', ['id' => OfficialResultsFixture::PARTICIPANT_ANNA]));
    }

    public function testAnEntryWithAResultOrAQualifiedMarkStays(): void
    {
        $protected = [
            [OfficialResultsFixture::ROUND_GROUP_A, 'participant_round:' . OfficialResultsFixture::ENTRY_A_DAN, OfficialResultsProtected::ROUND_ENTRY_HAS_RESULT],
            [OfficialResultsFixture::ROUND_PAIRS, 'team:' . OfficialResultsFixture::TEAM_SHARKS, OfficialResultsProtected::TEAM_HAS_RESULT],
        ];

        foreach ($protected as [$roundId, $entry, $reason]) {
            try {
                $this->takeOut($roundId, $entry);
                self::fail('Official data must stay.');
            } catch (OfficialResultsProtected $refused) {
                self::assertSame($reason, $refused->reason);
            }
        }

        // A qualified mark alone protects too
        $this->database->executeStatement('UPDATE competition_participant_round SET qualified_at = NOW() WHERE id = :id', ['id' => OfficialResultsFixture::ENTRY_FINAL_ANNA]);

        $this->expectException(OfficialResultsProtected::class);
        $this->takeOut(OfficialResultsFixture::ROUND_FINAL, 'participant_round:' . OfficialResultsFixture::ENTRY_FINAL_ANNA);
    }

    public function testAPairWithoutAResultLeavesWithItsMembersPlacesInTheRound(): void
    {
        $this->takeOut(OfficialResultsFixture::ROUND_PAIRS, 'team:' . OfficialResultsFixture::TEAM_UNNAMED);

        self::assertFalse($this->database->fetchOne('SELECT 1 FROM competition_team WHERE id = :id', ['id' => OfficialResultsFixture::TEAM_UNNAMED]));
        self::assertSame(0, $this->database->fetchOne(
            'SELECT COUNT(*) FROM competition_participant_round WHERE round_id = :round AND participant_id IN (:eva, :filip)',
            ['round' => OfficialResultsFixture::ROUND_PAIRS, 'eva' => OfficialResultsFixture::PARTICIPANT_EVA, 'filip' => OfficialResultsFixture::PARTICIPANT_FILIP],
        ));
        // Their solo entries stay
        self::assertNotFalse($this->database->fetchOne('SELECT 1 FROM competition_participant_round WHERE id = :id', ['id' => OfficialResultsFixture::ENTRY_A_EVA]));
    }

    public function testOnlyAnEntryOfThisRoundOfThisEvent(): void
    {
        try {
            // Anna's Group A entry is not an entry of the final
            $this->takeOut(OfficialResultsFixture::ROUND_FINAL, 'participant_round:' . OfficialResultsFixture::ENTRY_A_ANNA);
            self::fail('Another round\'s entry must not be found.');
        } catch (RoundEntryNotFound) {
        }

        try {
            $this->takeOut(OfficialResultsFixture::ROUND_PAIRS, 'participant_round:' . OfficialResultsFixture::ENTRY_A_FILIP);
            self::fail('A pair round has pairs as its entries.');
        } catch (RoundEntryNotFound) {
        }

        $this->expectException(CompetitionRoundNotFound::class);
        $this->messageBus->dispatch(new TakeEntryOutOfRound(CompetitionFixture::COMPETITION_WJPC_2024, OfficialResultsFixture::ROUND_FINAL, 'participant_round:' . OfficialResultsFixture::ENTRY_FINAL_ANNA));
    }

    public function testItTakesTheEventsParticipantsLock(): void
    {
        $message = new TakeEntryOutOfRound(OfficialResultsFixture::COMPETITION_RESULTS_CUP, OfficialResultsFixture::ROUND_FINAL, 'participant_round:' . OfficialResultsFixture::ENTRY_FINAL_ANNA);

        self::assertContains(SerializedByLock::class, class_implements($message));
        self::assertSame('participant-import-' . OfficialResultsFixture::COMPETITION_RESULTS_CUP, $message->lockKey());
    }

    private function takeOut(string $roundId, string $entry): void
    {
        $this->messageBus->dispatch(new TakeEntryOutOfRound(OfficialResultsFixture::COMPETITION_RESULTS_CUP, $roundId, $entry));
    }
}
