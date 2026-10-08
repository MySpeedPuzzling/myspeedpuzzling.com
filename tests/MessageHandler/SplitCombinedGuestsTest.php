<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\SplitCombinedGuests;
use SpeedPuzzling\Web\Services\PuzzlersGrouping;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * "Anna, Ben, Clara" typed into the guest box are three people: a team of four, never a pair with one
 * oddly named guest.
 */
final class SplitCombinedGuestsTest extends KernelTestCase
{
    private int $savedResults = 0;

    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testCommaSeparatedInputIsSeveralPeople(): void
    {
        self::assertSame(['Anna', 'Ben', 'Clara', '#admin', 'Alex'], PuzzlersGrouping::splitInputs(['Anna, Ben,Clara', ' #admin ', 'Alex,', ',', '']));
    }

    public function testAddingWithCommaSeparatedGuestsSavesATeam(): void
    {
        $time = $this->addTime(['Anna, Ben, Clara']);

        self::assertSame(['Anna', 'Ben', 'Clara'], $this->guestNamesOf($time));
        self::assertSame(4, $this->database->fetchOne('SELECT puzzlers_count FROM puzzle_solving_time WHERE id = :id', ['id' => $time]));
        self::assertSame(4, $this->database->fetchOne('SELECT size FROM puzzling_team t INNER JOIN puzzle_solving_time s ON s.puzzling_team_id = t.id WHERE s.id = :id', ['id' => $time]));
    }

    public function testCombinedGuestSavedEarlierIsSplitIntoItsPeople(): void
    {
        $combined = $this->addTime(['Anna']);
        $this->combineGuest($combined, 'Anna', 'Anna, Ben, Clara');
        $untouched = $this->addTime(['Anna']);
        $pairTeamId = $this->teamIdOf($combined);

        // Dry run: listed, nothing changes
        $changes = $this->split(dryRun: true);
        self::assertSame([['timeId' => $combined, 'before' => ['Anna, Ben, Clara'], 'after' => ['Anna', 'Ben', 'Clara']]], array_map(
            static fn(array $change): array => ['timeId' => $change['timeId'], 'before' => array_slice($change['before'], 1), 'after' => array_slice($change['after'], 1)],
            $changes,
        ));
        self::assertSame($pairTeamId, $this->teamIdOf($combined));

        $this->split(dryRun: false);

        self::assertSame(['Anna', 'Ben', 'Clara'], $this->guestNamesOf($combined));
        self::assertSame(4, $this->database->fetchOne('SELECT puzzlers_count FROM puzzle_solving_time WHERE id = :id', ['id' => $combined]));
        self::assertNotSame($pairTeamId, $this->teamIdOf($combined));

        // The same four saved today are that team
        self::assertSame($this->teamIdOf($combined), $this->teamIdOf($this->addTime(['Clara', 'Anna', 'Ben'])));
        self::assertSame(['Anna'], $this->guestNamesOf($untouched));
        self::assertSame([], $this->split(dryRun: true));
    }

    /**
     * @return list<array{timeId: string, before: list<string>, after: list<string>}>
     */
    private function split(bool $dryRun): array
    {
        /** @var list<array{timeId: string, before: list<string>, after: list<string>}> $changes */
        $changes = $this->messageBus->dispatch(new SplitCombinedGuests($dryRun))->last(HandledStamp::class)?->getResult();

        return array_values(array_filter($changes, static fn(array $change): bool => in_array('Anna, Ben, Clara', $change['before'], true)));
    }

    /**
     * What the picker saved before it split commas: one guest of that name, in the team and in the snapshot
     */
    private function combineGuest(string $timeId, string $name, string $combinedName): void
    {
        $teamId = $this->teamIdOf($timeId);
        $this->database->executeStatement(
            'UPDATE puzzling_team_member SET guest_name = :combined, member_key = :key WHERE team_id = :team AND guest_name = :name',
            ['combined' => $combinedName, 'key' => 'g:' . mb_strtolower($combinedName), 'team' => $teamId, 'name' => $name],
        );
        $this->database->executeStatement(
            "UPDATE puzzle_solving_time SET team = REPLACE(team::text, :name, :combined)::json WHERE id = :id",
            ['name' => '"' . $name . '"', 'combined' => '"' . $combinedName . '"', 'id' => $timeId],
        );
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /**
     * @param array<string> $groupPlayers
     */
    private function addTime(array $groupPlayers): string
    {
        $timeId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            puzzleId: PuzzleFixture::PUZZLE_1500_01,
            competitionId: null,
            time: sprintf('05:01:%02d', $this->savedResults++),
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: $groupPlayers,
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
        ));

        return $timeId->toString();
    }

    private function teamIdOf(string $timeId): string
    {
        $teamId = $this->database->fetchOne('SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
        self::assertIsString($teamId);

        return $teamId;
    }

    /**
     * @return list<string>
     */
    private function guestNamesOf(string $timeId): array
    {
        /** @var list<string> $names */
        $names = $this->database->fetchFirstColumn(
            "SELECT puzzler ->> 'player_name' FROM puzzle_solving_time, json_array_elements(team -> 'puzzlers') AS puzzler WHERE id = :id AND puzzler ->> 'player_id' IS NULL",
            ['id' => $timeId],
        );

        return $names;
    }
}
