<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RoundsInternalApiTest extends WebTestCase
{
    use InternalApiRequests;

    public function testCreatesARoundWithItsPuzzles(): void
    {
        $browser = self::createClient();

        $round = self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024 . '/rounds', [
            'name' => 'Pairs Final',
            'category' => 'duo',
            // No offset: the time in Prague, the time zone of the competition's country (CEST in October)
            'startsAt' => '2026-10-10T10:00:00',
            'minutesLimit' => 90,
            'resultsLink' => 'https://example.com/pairs-final',
            // Already in the solo Qualification round - a duo round may use it too
            'puzzleIds' => [PuzzleFixture::PUZZLE_500_01],
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('Pairs Final', $round['name']);
        self::assertSame('pairs-final', $round['slug']);
        self::assertSame('duo', $round['category']);
        self::assertSame('2026-10-10T08:00:00+00:00', $round['startsAt']);
        self::assertSame(90, $round['minutesLimit']);
        self::assertSame('https://example.com/pairs-final', $round['resultsLink']);
        self::assertSame(CompetitionFixture::COMPETITION_WJPC_2024, $round['competitionId']);
        self::assertSame([PuzzleFixture::PUZZLE_500_01], array_column(self::list($round['puzzles']), 'puzzleId'));

        $competition = self::callInternalApi($browser, 'GET', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024);
        self::assertContains($round['roundId'], array_column(self::list($competition['rounds']), 'roundId'));
    }

    public function testStartsAtTakesAnOffsetOrATimeZone(): void
    {
        $browser = self::createClient();

        $withOffset = $this->createRound($browser, ['startsAt' => '2026-01-10T10:00:00+01:00']);
        self::assertSame('2026-01-10T09:00:00+00:00', $withOffset['startsAt']);

        $withTimeZone = $this->createRound($browser, ['name' => 'Evening Round', 'startsAt' => '2026-01-10T10:00', 'timezone' => 'America/New_York']);
        self::assertSame('2026-01-10T15:00:00+00:00', $withTimeZone['startsAt']);
    }

    public function testInvalidRoundIsRefusedWithFieldErrors(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024 . '/rounds', [
            'category' => 'quartet',
            'startsAt' => 'tomorrow',
            'timezone' => 'Mars/Olympus',
            'minutesLimit' => 0,
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertIsArray($answer['errors']);
        foreach (['name', 'category', 'startsAt', 'timezone', 'minutesLimit'] as $field) {
            self::assertArrayHasKey($field, $answer['errors'], $field);
        }

        self::callInternalApi($browser, 'POST', '/internal-api/competitions/018d0004-0000-0000-0000-00000000ffff/rounds', [
            'name' => 'Lost',
            'startsAt' => '2026-01-10T10:00:00Z',
            'minutesLimit' => 60,
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testARoundWhosePuzzlesAreRefusedIsNotCreated(): void
    {
        $browser = self::createClient();
        $roundsBefore = self::callInternalApi($browser, 'GET', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024)['roundsCount'];

        self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024 . '/rounds', [
            'name' => 'Second Solo Round',
            'startsAt' => '2026-01-10T10:00:00Z',
            'minutesLimit' => 60,
            // In the solo Qualification Round already
            'puzzleIds' => [PuzzleFixture::PUZZLE_500_01],
        ]);
        self::assertResponseStatusCodeSame(409);

        self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024 . '/rounds', [
            'name' => 'Round Of Unknowns',
            'startsAt' => '2026-01-10T10:00:00Z',
            'minutesLimit' => 60,
            'puzzleIds' => ['018d0003-0000-0000-0000-00000000ffff'],
        ]);
        self::assertResponseStatusCodeSame(404);

        $roundsAfter = self::callInternalApi($browser, 'GET', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024)['roundsCount'];
        self::assertSame($roundsBefore, $roundsAfter);
    }

    public function testATimeZoneWithoutStartsAtIsRefused(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'PATCH', '/internal-api/rounds/' . CompetitionRoundFixture::ROUND_WJPC_FINAL, [
            'timezone' => 'America/New_York',
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertIsArray($answer['errors']);
        self::assertArrayHasKey('timezone', $answer['errors']);
    }

    public function testUpdatesOnlyTheFieldsSentAndKeepsTheSlug(): void
    {
        $browser = self::createClient();
        $before = self::round($browser, CompetitionRoundFixture::ROUND_WJPC_FINAL);

        $round = self::callInternalApi($browser, 'PATCH', '/internal-api/rounds/' . CompetitionRoundFixture::ROUND_WJPC_FINAL, [
            'name' => 'Grand Final',
            'minutesLimit' => 150,
            'resultsLink' => 'https://example.com/final',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('Grand Final', $round['name']);
        self::assertSame(150, $round['minutesLimit']);
        self::assertSame('https://example.com/final', $round['resultsLink']);
        self::assertSame($before['slug'], $round['slug']);
        self::assertSame($before['startsAt'], $round['startsAt']);
        self::assertSame($before['category'], $round['category']);

        self::callInternalApi($browser, 'PATCH', '/internal-api/rounds/018d0005-0000-0000-0000-00000000ffff', ['name' => 'X']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testACategoryChangeMayNotPutAPuzzleIntoTwoRoundsOfOneCategory(): void
    {
        $browser = self::createClient();
        $pairs = $this->createRound($browser, ['category' => 'duo', 'puzzleIds' => [PuzzleFixture::PUZZLE_500_01]]);

        $answer = self::callInternalApi($browser, 'PATCH', '/internal-api/rounds/' . self::string($pairs['roundId']), ['category' => 'solo']);

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('Qualification Round', self::string($answer['error']));
        self::assertSame('duo', self::round($browser, self::string($pairs['roundId']))['category']);
    }

    public function testSetsTheRoundPuzzlesAndReconcilesItsResults(): void
    {
        $browser = self::createClient();
        $qualification = CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION;
        self::assertSame(3, self::round($browser, $qualification)['resultsCount']);

        // The three results are on PUZZLE_500_01 - without it they belong to no round
        $round = self::callInternalApi($browser, 'PUT', '/internal-api/rounds/' . $qualification . '/puzzles', [
            'puzzleIds' => [PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_500_03],
        ]);

        self::assertResponseIsSuccessful();
        self::assertEqualsCanonicalizing(
            [PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_500_03],
            array_column(self::list($round['puzzles']), 'puzzleId'),
        );
        self::assertSame(0, $round['resultsCount']);

        $restored = self::callInternalApi($browser, 'PUT', '/internal-api/rounds/' . $qualification . '/puzzles', [
            'puzzleIds' => [PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_02],
        ]);
        self::assertSame(3, $restored['resultsCount']);

        $emptied = self::callInternalApi($browser, 'PUT', '/internal-api/rounds/' . $qualification . '/puzzles', ['puzzleIds' => []]);
        self::assertSame([], $emptied['puzzles']);
    }

    public function testAPuzzleIsInOnlyOneRoundPerCategoryOfACompetition(): void
    {
        $browser = self::createClient();
        $final = CompetitionRoundFixture::ROUND_WJPC_FINAL;

        // PUZZLE_500_01 is in the solo Qualification Round already
        $answer = self::callInternalApi($browser, 'PUT', '/internal-api/rounds/' . $final . '/puzzles', [
            'puzzleIds' => [PuzzleFixture::PUZZLE_1000_01, PuzzleFixture::PUZZLE_500_01],
        ]);

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('Qualification Round', self::string($answer['error']));
        // Nothing changed - not even the removal of PUZZLE_1000_02
        self::assertEqualsCanonicalizing(
            [PuzzleFixture::PUZZLE_1000_01, PuzzleFixture::PUZZLE_1000_02],
            array_column(self::list(self::round($browser, $final)['puzzles']), 'puzzleId'),
        );
    }

    public function testRoundPuzzlesRefuseUnknownPuzzlesAndRounds(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'PUT', '/internal-api/rounds/' . CompetitionRoundFixture::ROUND_WJPC_FINAL . '/puzzles', [
            'puzzleIds' => ['018d0003-0000-0000-0000-00000000ffff'],
        ]);
        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('018d0003-0000-0000-0000-00000000ffff', self::string($answer['error']));

        self::callInternalApi($browser, 'PUT', '/internal-api/rounds/018d0005-0000-0000-0000-00000000ffff/puzzles', ['puzzleIds' => []]);
        self::assertResponseStatusCodeSame(404);

        $invalid = self::callInternalApi($browser, 'PUT', '/internal-api/rounds/' . CompetitionRoundFixture::ROUND_WJPC_FINAL . '/puzzles', [
            'puzzleIds' => ['not-an-id'],
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertIsArray($invalid['errors']);
        self::assertArrayHasKey('puzzleIds', $invalid['errors']);
    }

    public function testARoundWithResultsIsNotDeleted(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'DELETE', '/internal-api/rounds/' . CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('3 result', self::string($answer['error']));
        self::assertSame(3, self::round($browser, CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION)['resultsCount']);
    }

    public function testDeletesARoundWithoutResults(): void
    {
        $browser = self::createClient();
        $round = $this->createRound($browser, ['puzzleIds' => [PuzzleFixture::PUZZLE_300]]);
        $roundId = self::string($round['roundId']);

        self::callInternalApi($browser, 'DELETE', '/internal-api/rounds/' . $roundId);
        self::assertResponseStatusCodeSame(204);

        $competition = self::callInternalApi($browser, 'GET', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024);
        self::assertNotContains($roundId, array_column(self::list($competition['rounds']), 'roundId'));

        self::callInternalApi($browser, 'DELETE', '/internal-api/rounds/' . $roundId);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function createRound(KernelBrowser $browser, array $fields): array
    {
        $round = self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024 . '/rounds', $fields + [
            'name' => 'Extra Round',
            'startsAt' => '2026-01-10T10:00:00Z',
            'minutesLimit' => 60,
        ]);
        self::assertResponseStatusCodeSame(201);

        return $round;
    }

    /**
     * @return array<string, mixed>
     */
    private static function round(KernelBrowser $browser, string $roundId): array
    {
        $competition = self::callInternalApi($browser, 'GET', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024);

        foreach (self::list($competition['rounds']) as $round) {
            if ($round['roundId'] === $roundId) {
                return $round;
            }
        }

        self::fail('Round ' . $roundId . ' not found');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function list(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var list<array<string, mixed>> $value */
        return $value;
    }

    private static function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
