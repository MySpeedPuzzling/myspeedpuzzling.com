<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

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

    public function testARoundKeepsItsTimeZoneAndAPatchOfItsNameKeepsItsStart(): void
    {
        $browser = self::createClient();

        // Wisconsin: a wall-clock time in Chicago (CDT in October)
        $round = $this->createRound($browser, ['name' => 'Team Relay', 'startsAt' => '2026-10-10T08:05', 'timezone' => 'America/Chicago']);
        self::assertSame('2026-10-10T13:05:00+00:00', $round['startsAt']);
        self::assertSame('America/Chicago', $round['timezone']);
        $roundId = self::string($round['roundId']);

        // The stored start is an instant - a rename must not read it as a wall clock and move the round
        $renamed = self::callInternalApi($browser, 'PATCH', '/internal-api/rounds/' . $roundId, ['name' => 'Team Relay Final']);
        self::assertResponseIsSuccessful();
        self::assertSame('Team Relay Final', $renamed['name']);
        self::assertSame('2026-10-10T13:05:00+00:00', $renamed['startsAt']);
        self::assertSame('America/Chicago', $renamed['timezone']);

        // A wall-clock time without a zone is read in the round's own zone, not the competition country's
        $moved = self::callInternalApi($browser, 'PATCH', '/internal-api/rounds/' . $roundId, ['startsAt' => '2026-10-10T09:00']);
        self::assertSame('2026-10-10T14:00:00+00:00', $moved['startsAt']);
        self::assertSame('America/Chicago', $moved['timezone']);

        // An offset is the moment itself; the zone sent along becomes the round's
        $rezoned = self::callInternalApi($browser, 'PATCH', '/internal-api/rounds/' . $roundId, ['startsAt' => '2026-10-10T16:00:00+02:00', 'timezone' => 'Europe/Prague']);
        self::assertSame('2026-10-10T14:00:00+00:00', $rezoned['startsAt']);
        self::assertSame('Europe/Prague', $rezoned['timezone']);
    }

    public function testAPatchOfOneFieldKeepsEveryOther(): void
    {
        $browser = self::createClient();
        $round = $this->createRound($browser, [
            'name' => 'Team Relay',
            'category' => 'team',
            'startsAt' => '2026-10-10T08:05',
            'timezone' => 'America/Chicago',
            'minutesLimit' => 75,
            'badgeBackgroundColor' => '#123456',
            'badgeTextColor' => '#abcdef',
            'resultsLink' => 'https://example.com/relay',
        ]);

        $patched = self::callInternalApi($browser, 'PATCH', '/internal-api/rounds/' . self::string($round['roundId']), ['name' => 'Team Relay Final']);
        self::assertResponseIsSuccessful();

        self::assertSame('Team Relay Final', $patched['name']);
        foreach (['category', 'startsAt', 'timezone', 'minutesLimit', 'badgeBackgroundColor', 'badgeTextColor', 'resultsLink', 'slug'] as $field) {
            self::assertSame($round[$field], $patched[$field], $field);
        }
    }

    public function testAWallClockTimeSkippedByADaylightSavingChangeIsRefused(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024 . '/rounds', [
            'name' => 'Lost Hour',
            // 02:30 does not exist in Chicago on 2026-03-08 (clocks jump from 02:00 to 03:00)
            'startsAt' => '2026-03-08T02:30',
            'timezone' => 'America/Chicago',
            'minutesLimit' => 60,
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertIsArray($answer['errors']);
        self::assertArrayHasKey('startsAt', $answer['errors']);
    }

    public function testAChangeThatRevealsASecretPuzzleNeedsAYes(): void
    {
        $browser = self::createClient();
        [$roundId, $puzzleId] = $this->secretPuzzleRevealedElsewhere($browser);

        // Deleting the round that still keeps it secret would reveal it: the other round revealed it already
        $deleted = self::callInternalApi($browser, 'DELETE', '/internal-api/rounds/' . $roundId);
        self::assertResponseStatusCodeSame(409);
        self::assertSame([$puzzleId], array_column(self::list($deleted['revealedPuzzles']), 'puzzleId'));

        // So would removing it, or moving the round into the past
        $removed = self::callInternalApi($browser, 'PUT', '/internal-api/rounds/' . $roundId . '/puzzles', ['puzzleIds' => []]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame([$puzzleId], array_column(self::list($removed['revealedPuzzles']), 'puzzleId'));

        $moved = self::callInternalApi($browser, 'PATCH', '/internal-api/rounds/' . $roundId, ['startsAt' => '2025-01-10T10:00:00Z']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame([$puzzleId], array_column(self::list($moved['revealedPuzzles']), 'puzzleId'));

        // Nothing changed
        $round = self::round($browser, $roundId);
        self::assertSame([$puzzleId], array_column(self::list($round['puzzles']), 'puzzleId'));
        self::assertTrue($this->puzzle($puzzleId)->isHiddenAt(new DateTimeImmutable()));

        // With the yes it goes ahead
        self::callInternalApi($browser, 'DELETE', '/internal-api/rounds/' . $roundId, ['confirmReveal' => true]);
        self::assertResponseStatusCodeSame(204);
        self::assertFalse($this->puzzle($puzzleId)->isHiddenAt(new DateTimeImmutable()));
    }

    public function testARemovalConfirmedRevealsThePuzzle(): void
    {
        $browser = self::createClient();
        [$roundId, $puzzleId] = $this->secretPuzzleRevealedElsewhere($browser);

        $round = self::callInternalApi($browser, 'PUT', '/internal-api/rounds/' . $roundId . '/puzzles', ['puzzleIds' => [], 'confirmReveal' => true]);

        self::assertResponseIsSuccessful();
        self::assertSame([], $round['puzzles']);
        self::assertFalse($this->puzzle($puzzleId)->isHiddenAt(new DateTimeImmutable()));
    }

    public function testAHiddenPuzzleIsNeverAttachedUnhidden(): void
    {
        $browser = self::createClient();
        $secretRound = $this->createRound($browser, ['name' => 'Secret Round', 'startsAt' => (new DateTimeImmutable('+30 days'))->format(DATE_ATOM)]);
        $puzzleId = $this->secretPuzzleIn(self::string($secretRound['roundId']));
        $otherRound = $this->createRound($browser, ['name' => 'Open Round', 'category' => 'duo']);

        // The event page of the other round would show it
        self::callInternalApi($browser, 'PUT', '/internal-api/rounds/' . self::string($otherRound['roundId']) . '/puzzles', ['puzzleIds' => [$puzzleId]]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame([], self::round($browser, self::string($otherRound['roundId']))['puzzles']);

        $roundsBefore = self::callInternalApi($browser, 'GET', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024)['roundsCount'];
        self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024 . '/rounds', [
            'name' => 'Leaky Round',
            'category' => 'team',
            'startsAt' => '2026-01-10T10:00:00Z',
            'minutesLimit' => 60,
            'puzzleIds' => [$puzzleId],
        ]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame($roundsBefore, self::callInternalApi($browser, 'GET', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024)['roundsCount']);

        // A placeholder hidden by hand is no round's at all
        $placeholder = $this->puzzle(PuzzleFixture::PUZZLE_300);
        $placeholder->approved = true;
        $placeholder->keepSecretUntil(new DateTimeImmutable('2099-01-01'), new DateTimeImmutable('2099-01-01'));
        $this->entityManager()->flush();

        self::callInternalApi($browser, 'PUT', '/internal-api/rounds/' . self::string($otherRound['roundId']) . '/puzzles', ['puzzleIds' => [PuzzleFixture::PUZZLE_300]]);
        self::assertResponseStatusCodeSame(409);
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
     * A puzzle created secret for a round in a month, also used secret in a round that started already - that one's
     * reveal is over, so only the first round keeps it secret now.
     *
     * @return array{string, string} the round keeping it secret, the puzzle
     */
    private function secretPuzzleRevealedElsewhere(KernelBrowser $browser): array
    {
        $secretRound = $this->createRound($browser, ['name' => 'Secret Round', 'startsAt' => (new DateTimeImmutable('+30 days'))->format(DATE_ATOM)]);
        $secretRoundId = self::string($secretRound['roundId']);
        $puzzleId = $this->secretPuzzleIn($secretRoundId);

        $pastRound = $this->createRound($browser, ['name' => 'Past Round', 'category' => 'duo', 'startsAt' => '2025-01-10T10:00:00Z']);
        $this->addSecretly(self::string($pastRound['roundId']), $puzzleId);

        return [$secretRoundId, $puzzleId];
    }

    private function secretPuzzleIn(string $roundId): string
    {
        $roundPuzzleId = Uuid::uuid7();
        $this->dispatchAdd($roundPuzzleId->toString(), $roundId, 'Secret ' . $roundPuzzleId->toString(), 500);

        $roundPuzzle = $this->entityManager()->find(CompetitionRoundPuzzle::class, $roundPuzzleId->toString());
        self::assertNotNull($roundPuzzle);

        return $roundPuzzle->puzzle->id->toString();
    }

    private function addSecretly(string $roundId, string $puzzleId): void
    {
        $this->dispatchAdd(Uuid::uuid7()->toString(), $roundId, $puzzleId, null);
    }

    private function dispatchAdd(string $roundPuzzleId, string $roundId, string $puzzle, null|int $piecesCount): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: Uuid::fromString($roundPuzzleId),
            roundId: $roundId,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: $puzzle,
            piecesCount: $piecesCount,
            puzzlePhoto: null,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
            hideUntilRoundStarts: true,
        ));
        $this->entityManager()->clear();
    }

    private function puzzle(string $puzzleId): Puzzle
    {
        $this->entityManager()->clear();
        $puzzle = $this->entityManager()->find(Puzzle::class, $puzzleId);
        self::assertNotNull($puzzle);

        return $puzzle;
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
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
