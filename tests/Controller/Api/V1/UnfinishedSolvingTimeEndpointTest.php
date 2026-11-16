<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddUnfinishedPuzzleSolvingTime;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\PatTestHelper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * `pieces_placed` on `POST` / `PUT /api/v1/me/solving-times` (docs/features/competitions-management/
 * unfinished-results-plan.md, step 4) - additive: without it every request behaves as before. WJPC 2024's Qualification
 * Round: 60 min, solo, PUZZLE_500_02 (500 pieces).
 */
final class UnfinishedSolvingTimeEndpointTest extends WebTestCase
{
    public function testCreateAnUnfinishedResult(): void
    {
        $browser = $this->authorised();

        $answer = $this->send($browser, 'POST', '/api/v1/me/solving-times', [
            'puzzle_id' => PuzzleFixture::PUZZLE_500_02,
            'round_id' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION,
            'pieces_placed' => 479,
        ]);

        self::assertResponseIsSuccessful();
        self::assertNull($answer['time_seconds']);
        self::assertSame(479, $answer['pieces_placed']);
        self::assertNull($answer['finished_later_seconds']);
        self::assertSame(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, $answer['round_id']);
        self::assertIsString($answer['time_id']);
        self::assertSame(['pieces_placed' => 479, 'seconds_to_solve' => null, 'finished_later_seconds' => null, 'created_via' => 'api'], $this->stored($answer['time_id']));
    }

    public function testATimeWithPiecesPlacedIsTheTotal(): void
    {
        $browser = $this->authorised();

        $answer = $this->send($browser, 'POST', '/api/v1/me/solving-times', [
            'puzzle_id' => PuzzleFixture::PUZZLE_500_02,
            'competition_id' => CompetitionFixture::COMPETITION_WJPC_2024,
            'pieces_placed' => 479,
            'time' => '01:12:00',
        ]);

        self::assertResponseIsSuccessful();
        self::assertNull($answer['time_seconds']);
        self::assertSame(4320, $answer['finished_later_seconds']);
    }

    public function testWithoutPiecesPlacedATimeIsStillRequired(): void
    {
        $browser = $this->authorised();

        $answer = $this->send($browser, 'POST', '/api/v1/me/solving-times', ['puzzle_id' => PuzzleFixture::PUZZLE_500_02]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('time', self::violationPath($answer));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideRefusals(): iterable
    {
        yield 'no event' => [['pieces_placed' => 479], 'pieces_placed'];
        yield 'all pieces' => [['round_id' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, 'pieces_placed' => 500], 'pieces_placed'];
        yield 'zero pieces' => [['round_id' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, 'pieces_placed' => 0], 'pieces_placed'];
        yield 'a total within the limit' => [['round_id' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, 'pieces_placed' => 479, 'time' => '45:00'], 'time'];
        yield 'a pair on a solo round' => [['round_id' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, 'pieces_placed' => 479, 'group_players' => ['Guest Friend']], 'pieces_placed'];
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideRefusals')]
    public function testRefusalsAre422ViolationsAndSaveNothing(array $fields, string $field): void
    {
        $browser = $this->authorised();
        $before = $this->countTimes();

        $answer = $this->send($browser, 'POST', '/api/v1/me/solving-times', ['puzzle_id' => PuzzleFixture::PUZZLE_500_02, ...$fields]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($field, self::violationPath($answer), (string) json_encode($answer));
        self::assertSame($before, $this->countTimes());
    }

    public function testUpdateChangesItsPiecesAndTotal(): void
    {
        $browser = $this->authorised();
        $timeId = $this->unfinishedResult();

        $answer = $this->send($browser, 'PUT', '/api/v1/me/solving-times/' . $timeId, ['pieces_placed' => 450, 'time' => '1:05:00', 'comment' => 'from the API']);

        self::assertResponseIsSuccessful();
        self::assertSame(450, $answer['pieces_placed']);
        self::assertSame(['pieces_placed' => 450, 'seconds_to_solve' => null, 'finished_later_seconds' => 3900, 'created_via' => null], $this->stored($timeId));
    }

    public function testAClientThatDoesNotKnowTheFieldKeepsItUnfinished(): void
    {
        $browser = $this->authorised();
        $timeId = $this->unfinishedResult('1:10:00');

        // What an older client sends back after reading the result: a null time, no pieces_placed
        $this->send($browser, 'PUT', '/api/v1/me/solving-times/' . $timeId, ['time' => null, 'comment' => 'just a comment']);

        self::assertResponseIsSuccessful();
        self::assertSame(['pieces_placed' => 479, 'seconds_to_solve' => null, 'finished_later_seconds' => 4200, 'created_via' => null], $this->stored($timeId));
    }

    public function testATimeWithoutPiecesPlacedFinishesIt(): void
    {
        $browser = $this->authorised();
        $timeId = $this->unfinishedResult();

        $answer = $this->send($browser, 'PUT', '/api/v1/me/solving-times/' . $timeId, ['time' => '0:59:00']);

        self::assertResponseIsSuccessful();
        self::assertNull($answer['pieces_placed']);
        self::assertSame(['pieces_placed' => null, 'seconds_to_solve' => 3540, 'finished_later_seconds' => null, 'created_via' => null], $this->stored($timeId));
    }

    public function testAnUpdateRefusalIs422AndChangesNothing(): void
    {
        $browser = $this->authorised();
        $timeId = $this->unfinishedResult();

        $answer = $this->send($browser, 'PUT', '/api/v1/me/solving-times/' . $timeId, ['pieces_placed' => 500]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('pieces_placed', self::violationPath($answer));
        self::assertSame(479, $this->stored($timeId)['pieces_placed']);
    }

    private function authorised(): KernelBrowser
    {
        $browser = self::createClient();
        PatTestHelper::addBearerToken($browser, PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR));

        return $browser;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function send(KernelBrowser $browser, string $method, string $url, array $body): array
    {
        $browser->request($method, $url, server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode($body));
        $answer = json_decode((string) $browser->getResponse()->getContent(), true);
        self::assertIsArray($answer, (string) $browser->getResponse()->getContent());

        /** @var array<string, mixed> $answer */
        return $answer;
    }

    private function unfinishedResult(null|string $totalTime = null): string
    {
        $timeId = Uuid::uuid7();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddUnfinishedPuzzleSolvingTime(
            timeId: $timeId,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleId: PuzzleFixture::PUZZLE_500_02,
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            piecesPlaced: 479,
            totalTime: $totalTime,
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: new DateTimeImmutable('2026-03-02'),
            firstAttempt: false,
            unboxed: false,
        ));

        return $timeId->toString();
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(string $timeId): array
    {
        $row = self::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT pieces_placed, seconds_to_solve, finished_later_seconds, created_via FROM puzzle_solving_time WHERE id = :id',
            ['id' => $timeId],
        );
        self::assertIsArray($row);

        return $row;
    }

    private function countTimes(): int
    {
        $count = self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM puzzle_solving_time');
        self::assertIsNumeric($count);

        return (int) $count;
    }

    /**
     * @param array<string, mixed> $answer
     */
    private static function violationPath(array $answer): null|string
    {
        $violations = $answer['violations'] ?? null;
        $first = is_array($violations) ? ($violations[0] ?? null) : null;
        $path = is_array($first) ? ($first['propertyPath'] ?? null) : null;

        return is_string($path) ? $path : null;
    }
}
