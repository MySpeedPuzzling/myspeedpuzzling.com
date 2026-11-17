<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Divisions through the internal API (docs/features/internal-api.md "Divisions", shared-puzzles-and-divisions.md A10):
 * the event's list with each division's rounds (GET/PUT /internal-api/competitions/{id}/divisions) and entries'
 * divisions of one round in bulk (PUT /internal-api/rounds/{id}/entry-divisions) - the same messages and refusals as
 * the divisions page.
 */
final class DivisionsInternalApiTest extends WebTestCase
{
    use InternalApiRequests;

    private const string LIST = '/internal-api/competitions/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP . '/divisions';

    public function testTheListIsSetReadAndEntriesGetTheirDivisions(): void
    {
        $browser = self::createClient();

        self::assertSame(['competitionId' => OfficialResultsFixture::COMPETITION_RESULTS_CUP, 'divisions' => []], self::callInternalApi($browser, 'GET', self::LIST));

        $answer = self::callInternalApi($browser, 'PUT', self::LIST, ['divisions' => [
            ['name' => 'Regular', 'roundIds' => [OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_PAIRS]],
            ['name' => 'Senior 55+', 'shortName' => 'S', 'roundIds' => [OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_PAIRS]],
            ['name' => 'Junior U16', 'shortName' => 'J', 'roundIds' => [OfficialResultsFixture::ROUND_GROUP_A]],
        ]]);
        self::assertResponseIsSuccessful();
        /** @var list<array{id: string, name: string, shortName: null|string, roundIds: list<string>, entriesCount: int}> $divisions */
        $divisions = $answer['divisions'];
        self::assertSame(['Regular', 'Senior 55+', 'Junior U16'], array_column($divisions, 'name'));
        self::assertCount(2, $divisions[1]['roundIds']);

        $entries = self::callInternalApi($browser, 'PUT', '/internal-api/rounds/' . OfficialResultsFixture::ROUND_GROUP_A . '/entry-divisions', ['entries' => [
            ['participantId' => OfficialResultsFixture::PARTICIPANT_ANNA, 'division' => 'Regular'],
            ['participantId' => OfficialResultsFixture::PARTICIPANT_BEN, 'division' => 'S'],
            ['participantId' => OfficialResultsFixture::PARTICIPANT_EVA, 'division' => $divisions[2]['id']],
        ]]);
        self::assertResponseIsSuccessful();
        self::assertSame(3, $entries['changed']);
        /** @var list<array{name: string, division: null|string, divisionRank: null|int}> $rows */
        $rows = $entries['entries'];
        self::assertSame(['name' => 'Ben Steady', 'division' => 'Senior 55+', 'divisionRank' => 1], array_intersect_key(array_values(array_filter($rows, static fn (array $row): bool => $row['name'] === 'Ben Steady'))[0], ['name' => 1, 'division' => 1, 'divisionRank' => 1]));

        // A pair by its team
        self::callInternalApi($browser, 'PUT', '/internal-api/rounds/' . OfficialResultsFixture::ROUND_PAIRS . '/entry-divisions', ['entries' => [
            ['teamId' => OfficialResultsFixture::TEAM_SHARKS, 'division' => 'Senior 55+'],
        ]]);
        self::assertResponseIsSuccessful();

        $list = self::callInternalApi($browser, 'GET', self::LIST);
        /** @var list<array{entriesCount: int}> $listed */
        $listed = $list['divisions'];
        self::assertSame([1, 2, 1], array_column($listed, 'entriesCount'));
    }

    public function testRefusalsChangeNothing(): void
    {
        $browser = self::createClient();
        self::callInternalApi($browser, 'PUT', self::LIST, ['divisions' => [
            ['name' => 'Regular', 'roundIds' => [OfficialResultsFixture::ROUND_GROUP_A]],
            ['name' => 'Senior', 'roundIds' => [OfficialResultsFixture::ROUND_GROUP_A]],
        ]]);
        self::callInternalApi($browser, 'PUT', '/internal-api/rounds/' . OfficialResultsFixture::ROUND_GROUP_A . '/entry-divisions', ['entries' => [
            ['participantId' => OfficialResultsFixture::PARTICIPANT_BEN, 'division' => 'Senior'],
        ]]);

        $duplicate = self::callInternalApi($browser, 'PUT', self::LIST, ['divisions' => [['name' => 'Senior'], ['name' => 'SENIOR']]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('duplicate_name', $duplicate['code']);

        $invalid = self::callInternalApi($browser, 'PUT', self::LIST, ['divisions' => [['title' => 'Senior']], 'other' => 1]);
        self::assertResponseStatusCodeSame(400);
        self::assertIsArray($invalid['errors']);
        self::assertArrayHasKey('other', $invalid['errors']);

        // Dropping Senior takes it from Ben - asked first
        $inUse = self::callInternalApi($browser, 'PUT', self::LIST, ['divisions' => [['name' => 'Regular']]]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('divisions_in_use', $inUse['code']);

        $notInRound = self::callInternalApi($browser, 'PUT', '/internal-api/rounds/' . OfficialResultsFixture::ROUND_PAIRS . '/entry-divisions', ['entries' => [
            ['teamId' => OfficialResultsFixture::TEAM_SHARKS, 'division' => 'Senior'],
        ]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('not_in_round', $notInRound['code']);

        $database = self::getContainer()->get(Connection::class);
        self::assertSame(2, (int) $database->fetchOne('SELECT COUNT(*) FROM competition_division WHERE competition_id = ?', [OfficialResultsFixture::COMPETITION_RESULTS_CUP]));
        self::assertNotNull($database->fetchOne('SELECT division_id FROM competition_participant_round WHERE id = ?', [OfficialResultsFixture::ENTRY_A_BEN]));

        // ... and confirmed
        self::callInternalApi($browser, 'PUT', self::LIST, ['divisions' => [['name' => 'Regular']], 'confirmClearing' => true]);
        self::assertResponseIsSuccessful();
        self::assertNull($database->fetchOne('SELECT division_id FROM competition_participant_round WHERE id = ?', [OfficialResultsFixture::ENTRY_A_BEN]));
    }
}
