<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\CompetitionDisplay;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\DisplayControlRefused;
use SpeedPuzzling\Web\Message\AddCompetitionDisplay;
use SpeedPuzzling\Web\Message\SetRoundSpotlight;
use SpeedPuzzling\Web\Results\DisplayControlSpotlight;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\DisplayKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * A round's spotlight (competition toolkit v2 L2, displays-standings.md §5.1) through the real bus: only an entry of
 * that round as the organiser's tools list it (a person of a solo round, a pair/team of a team round; nobody removed or
 * waitlisted), only for an event with a display; Clear takes it out; it goes with its round.
 */
final class RoundSpotlightTest extends KernelTestCase
{
    private const string EVENT = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
    private const string GROUP_A = OfficialResultsFixture::ROUND_GROUP_A;

    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testAnEntryOfTheRoundIsSpotlitAndCleared(): void
    {
        $this->addDisplay();

        $spotlight = $this->set(self::GROUP_A, 'participant_round:' . strtoupper(OfficialResultsFixture::ENTRY_A_ANNA));
        self::assertNotNull($spotlight);
        self::assertSame(['entryRef' => 'participant_round:' . OfficialResultsFixture::ENTRY_A_ANNA, 'name' => 'Anna Fast', 'table' => 1], $spotlight->jsonSerialize());
        self::assertSame('participant_round:' . OfficialResultsFixture::ENTRY_A_ANNA, $this->ref(self::GROUP_A));

        // Another entry replaces it (one row per round); a team of the pairs round by its team ref
        $this->set(self::GROUP_A, 'participant_round:' . OfficialResultsFixture::ENTRY_A_BEN);
        self::assertSame('participant_round:' . OfficialResultsFixture::ENTRY_A_BEN, $this->ref(self::GROUP_A));
        self::assertSame([self::GROUP_A], $this->database->fetchFirstColumn('SELECT round_id FROM competition_round_spotlight WHERE round_id = :id', ['id' => self::GROUP_A]));
        self::assertSame('Puzzle Sharks', $this->set(OfficialResultsFixture::ROUND_PAIRS, 'team:' . OfficialResultsFixture::TEAM_SHARKS)?->name);

        self::assertNull($this->set(self::GROUP_A, null));
        self::assertNull($this->ref(self::GROUP_A));
        // Clearing an empty spotlight is fine
        self::assertNull($this->set(self::GROUP_A, null));
    }

    public function testOnlyAnEntryOfThatRound(): void
    {
        $this->addDisplay();
        $this->set(self::GROUP_A, 'participant_round:' . OfficialResultsFixture::ENTRY_A_ANNA);

        $this->database->executeStatement(
            'UPDATE competition_participant SET deleted_at = ' . TestClock::SQL_NOW . ' WHERE id = :id',
            ['id' => OfficialResultsFixture::PARTICIPANT_FILIP],
        );

        $refused = [
            'another round\'s entry' => 'participant_round:' . OfficialResultsFixture::ENTRY_B_GINA,
            'a team in a solo round' => 'team:' . OfficialResultsFixture::TEAM_SHARKS,
            'a removed participant' => 'participant_round:' . OfficialResultsFixture::ENTRY_A_FILIP,
            'an unknown id' => 'participant_round:' . Uuid::uuid7()->toString(),
            'not a ref' => 'Anna Fast',
            'another kind' => 'player:' . PlayerFixture::PLAYER_ADMIN,
        ];

        foreach ($refused as $case => $ref) {
            try {
                $this->set(self::GROUP_A, $ref);
                self::fail($case . ' must be refused');
            } catch (DisplayControlRefused $refused) {
                self::assertSame(DisplayControlRefused::UNKNOWN_ENTRY, $refused->reason, $case);
            }
        }

        self::assertSame('participant_round:' . OfficialResultsFixture::ENTRY_A_ANNA, $this->ref(self::GROUP_A), 'nothing changed');
    }

    public function testAnEventWithoutDisplaysOrAnotherEventsRoundIsRefused(): void
    {
        try {
            $this->set(self::GROUP_A, 'participant_round:' . OfficialResultsFixture::ENTRY_A_ANNA);
            self::fail('An event without displays must be refused');
        } catch (DisplayControlRefused $refused) {
            self::assertSame(DisplayControlRefused::NO_DISPLAYS, $refused->reason);
        }

        $this->addDisplay();
        $this->expectException(CompetitionRoundNotFound::class);
        $this->messageBus->dispatch(new SetRoundSpotlight(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, self::GROUP_A, null, PlayerFixture::PLAYER_ADMIN));
    }

    public function testTheSpotlightGoesWithItsRound(): void
    {
        // Rounds are deleted in SQL (DeleteCompetitionHandler) and by the ORM, which never loads the spotlight - the
        // database removes it with its round
        $onDelete = $this->database->fetchOne(<<<SQL
SELECT c.confdeltype
FROM pg_constraint c
JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
WHERE c.conrelid = 'competition_round_spotlight'::regclass AND c.contype = 'f' AND a.attname = 'round_id'
SQL);

        self::assertSame('c', $onDelete);
    }

    private function set(string $roundId, null|string $ref): null|DisplayControlSpotlight
    {
        $result = $this->messageBus
            ->dispatch(new SetRoundSpotlight(self::EVENT, $roundId, $ref, PlayerFixture::PLAYER_ADMIN))
            ->last(HandledStamp::class)
            ?->getResult();
        assert($result === null || $result instanceof DisplayControlSpotlight);

        return $result;
    }

    private function ref(string $roundId): null|string
    {
        $ref = $this->database->fetchOne('SELECT entry_ref FROM competition_round_spotlight WHERE round_id = :id', ['id' => $roundId]);

        return is_string($ref) ? $ref : null;
    }

    private function addDisplay(): void
    {
        $this->messageBus->dispatch(new AddCompetitionDisplay(Uuid::uuid7(), self::EVENT, DisplayKind::Screen, 'Hall A', 'en', PlayerFixture::PLAYER_ADMIN));
    }
}
