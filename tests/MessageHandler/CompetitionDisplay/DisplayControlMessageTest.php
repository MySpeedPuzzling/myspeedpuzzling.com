<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\CompetitionDisplay;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\DisplayControlRefused;
use SpeedPuzzling\Web\Message\AddCompetitionDisplay;
use SpeedPuzzling\Web\Message\DeleteCompetition;
use SpeedPuzzling\Web\Message\SetCompetitionDisplayMessage;
use SpeedPuzzling\Web\Results\DisplayControlMessage;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\DisplayKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The live message of an event's displays (competition toolkit v2 L1, displays-standings.md §5.1) through the real bus:
 * one line, at most 200 characters (refused, never cut), the 5 recent ones newest first and each once, only for an event
 * with a display; it goes with the event.
 */
final class DisplayControlMessageTest extends KernelTestCase
{
    private const string EVENT = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
    private const string OTHER_EVENT = CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024;

    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testAMessageIsOneTrimmedLineAndTheRecentOnesAreKept(): void
    {
        $this->addDisplay(self::EVENT);

        $result = $this->set("  Break -\nnext round\t14:30  ");
        self::assertSame('Break - next round 14:30', $result->message);
        self::assertSame(['Break - next round 14:30'], $result->recentMessages);

        $row = $this->row(self::EVENT);
        self::assertSame('Break - next round 14:30', $row['message']);
        self::assertNotNull($row['message_set_at']);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $row['updated_by_id']);

        // Six more: five recent, newest first, a repeated one moves to the front instead of twice
        foreach (['A', 'B', 'C', 'Break - next round 14:30', 'D', 'E'] as $text) {
            $result = $this->set($text);
        }

        self::assertSame('E', $result->message);
        self::assertSame(['E', 'D', 'Break - next round 14:30', 'C', 'B'], $result->recentMessages);
    }

    public function testClearingKeepsTheRecentOnes(): void
    {
        $this->addDisplay(self::EVENT);

        // Nothing set yet: clearing writes nothing
        self::assertSame([null, []], [$this->set(null)->message, $this->set('   ')->recentMessages]);
        self::assertFalse($this->row(self::EVENT));

        $this->set('Welcome');
        $result = $this->set(null);

        self::assertNull($result->message);
        self::assertSame(['Welcome'], $result->recentMessages);
        $row = $this->row(self::EVENT);
        self::assertIsArray($row);
        self::assertNull($row['message']);
        self::assertNull($row['message_set_at']);
    }

    public function testATooLongMessageIsRefusedNeverCut(): void
    {
        $this->addDisplay(self::EVENT);
        $this->set('Kept');

        // 200 characters (multibyte ones count once) are fine
        self::assertSame(str_repeat('č', 200), $this->set(str_repeat('č', 200))->message);

        try {
            $this->set(str_repeat('x', 201));
            self::fail('A message over 200 characters must be refused');
        } catch (DisplayControlRefused $refused) {
            self::assertSame(DisplayControlRefused::TOO_LONG, $refused->reason);
        }

        $row = $this->row(self::EVENT);
        self::assertIsArray($row);
        self::assertSame(str_repeat('č', 200), $row['message']);
    }

    public function testAnEventWithoutDisplaysIsRefused(): void
    {
        try {
            $this->set('Nobody sees this', self::OTHER_EVENT);
            self::fail('An event without displays must be refused');
        } catch (DisplayControlRefused $refused) {
            self::assertSame(DisplayControlRefused::NO_DISPLAYS, $refused->reason);
        }

        self::assertFalse($this->row(self::OTHER_EVENT));
    }

    public function testTheMessageGoesWithTheEvent(): void
    {
        $this->addDisplay(self::OTHER_EVENT);
        $this->addDisplay(self::EVENT);
        $this->set('Gone soon', self::OTHER_EVENT);
        $this->set('Stays', self::EVENT);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->messageBus->dispatch(new DeleteCompetition(self::OTHER_EVENT));

        self::assertFalse($this->row(self::OTHER_EVENT));
        $row = $this->row(self::EVENT);
        self::assertIsArray($row);
        self::assertSame('Stays', $row['message']);
    }

    private function set(null|string $text, string $competitionId = self::EVENT): DisplayControlMessage
    {
        $result = $this->messageBus
            ->dispatch(new SetCompetitionDisplayMessage($competitionId, $text, PlayerFixture::PLAYER_ADMIN))
            ->last(HandledStamp::class)
            ?->getResult();
        self::assertInstanceOf(DisplayControlMessage::class, $result);

        return $result;
    }

    private function addDisplay(string $competitionId): void
    {
        $this->messageBus->dispatch(new AddCompetitionDisplay(Uuid::uuid7(), $competitionId, DisplayKind::Screen, 'Hall A', 'en', PlayerFixture::PLAYER_ADMIN));
    }

    /**
     * @return false|array<string, mixed>
     */
    private function row(string $competitionId): false|array
    {
        return $this->database->fetchAssociative(
            'SELECT message, message_set_at, recent_messages, updated_by_id FROM competition_display_control WHERE competition_id = :id',
            ['id' => $competitionId],
        );
    }
}
