<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Query\GetParticipantsSheetVersion;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as Cup;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The participants sheet's state version: everything the sheet shows that it, registrations and imports change - never
 * official results, table numbers or qualified marks (they travel on the rounds' topics).
 */
final class GetParticipantsSheetVersionTest extends KernelTestCase
{
    private Connection $database;
    private GetParticipantsSheetVersion $getVersion;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);
        $this->getVersion = self::getContainer()->get(GetParticipantsSheetVersion::class);
    }

    public function testTheVersionIsStableAndOfOneEventOnly(): void
    {
        $version = $this->version();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $version);
        self::assertSame($version, $this->version());
        self::assertSame($version, $this->getVersion->ofCompetition(strtoupper(Cup::COMPETITION_RESULTS_CUP)));
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function sheetChanges(): iterable
    {
        yield 'a name' => ["UPDATE competition_participant SET name = 'Ivan Renamed' WHERE id = :id", ['id' => Cup::PARTICIPANT_IVAN]];
        yield 'an organiser\'s note' => ["UPDATE competition_participant SET organizer_note = 'Vegan lunch' WHERE id = :id", ['id' => Cup::PARTICIPANT_IVAN]];
        yield 'a registration' => ["UPDATE competition_participant SET registration_status = 'paid', paid_at = NOW() WHERE id = :id", ['id' => Cup::PARTICIPANT_IVAN]];
        yield 'a check-in' => ['UPDATE competition_participant SET checked_in_at = NOW() WHERE id = :id', ['id' => Cup::PARTICIPANT_IVAN]];
        yield 'a removal' => ['UPDATE competition_participant SET deleted_at = NOW() WHERE id = :id', ['id' => Cup::PARTICIPANT_FILIP]];
        yield 'a place in a pair' => ['UPDATE competition_participant_round SET team_id = NULL WHERE participant_id = :id AND round_id = :round', ['id' => Cup::PARTICIPANT_EVA, 'round' => Cup::ROUND_PAIRS]];
        yield 'a pair\'s name' => ["UPDATE competition_team SET name = 'Sharks' WHERE id = :id", ['id' => Cup::TEAM_SHARKS]];
        yield 'a team size' => ['UPDATE competition_round SET team_size = 4 WHERE id = :id', ['id' => Cup::ROUND_PAIRS]];
        yield 'a round\'s start' => ["UPDATE competition_round SET starts_at = starts_at + INTERVAL '1 hour' WHERE id = :id", ['id' => Cup::ROUND_FINAL]];
        // What the sheet shows of the event and its rounds besides the people (review A-r5, B-m4)
        yield 'a registration time' => ['UPDATE competition_participant SET registered_at = NOW() WHERE id = :id', ['id' => Cup::PARTICIPANT_IVAN]];
        yield 'a round\'s time zone' => ["UPDATE competition_round SET timezone = 'America/Chicago' WHERE id = :id", ['id' => Cup::ROUND_FINAL]];
        yield 'a round\'s colour' => ["UPDATE competition_round SET badge_background_color = '#123456' WHERE id = :id", ['id' => Cup::ROUND_FINAL]];
        yield 'a round\'s text colour' => ["UPDATE competition_round SET badge_text_color = '#fefefe' WHERE id = :id", ['id' => Cup::ROUND_FINAL]];
        yield 'table numbers off' => ['UPDATE competition_round SET table_numbers_off = true WHERE id = :id', ['id' => Cup::ROUND_FINAL]];
        yield 'results published' => ['UPDATE competition_round SET results_published_at = NOW() WHERE id = :id', ['id' => Cup::ROUND_FINAL]];
        yield 'the event\'s name' => ["UPDATE competition SET name = 'Results Cup 2' WHERE id = :id", ['id' => Cup::COMPETITION_RESULTS_CUP]];
        yield 'managed registration' => ['UPDATE competition SET registration_managed = true WHERE id = :id', ['id' => Cup::COMPETITION_RESULTS_CUP]];
        yield 'the capacity' => ['UPDATE competition SET capacity = 120 WHERE id = :id', ['id' => Cup::COMPETITION_RESULTS_CUP]];
        yield 'online' => ['UPDATE competition SET is_online = true WHERE id = :id', ['id' => Cup::COMPETITION_RESULTS_CUP]];
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('sheetChanges')]
    public function testEveryChangeOfTheSheetChangesTheVersion(string $sql, array $parameters): void
    {
        $before = $this->version();

        $this->database->executeStatement($sql, $parameters);

        self::assertNotSame($before, $this->version());
    }

    public function testResultsTablesAndQualifiedMarksDoNotChangeIt(): void
    {
        $before = $this->version();

        $this->database->executeStatement('UPDATE competition_participant_round SET result_seconds = 4100, table_number = 9, qualified_at = NOW() WHERE id = :id', ['id' => Cup::ENTRY_A_FILIP]);
        $this->database->executeStatement('UPDATE competition_team SET result_seconds = 5000, table_number = 7, qualified_at = NULL WHERE id = :id', ['id' => Cup::TEAM_CORNERS]);

        self::assertSame($before, $this->version());
    }

    private function version(): string
    {
        return $this->getVersion->ofCompetition(Cup::COMPETITION_RESULTS_CUP);
    }
}
