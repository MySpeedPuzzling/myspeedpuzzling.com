<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddUnfinishedPuzzleSolvingTime;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * No edit surface may quietly destroy an unfinished result (docs/features/competitions-management/
 * unfinished-results-plan.md, step 3): My data's row sheet and cells save it through the unfinished edit - its pieces
 * placed stay. The edit form and the API edit it since step 4 (EditTimeUnfinishedResultTest,
 * ApiUnfinishedSolvingTimeTest).
 */
final class UnfinishedResultEditSurfacesTest extends WebTestCase
{
    private const array SAME_ORIGIN = ['HTTP_ORIGIN' => 'http://localhost'];
    private const array JSON = ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_X_CSRF_TOKEN' => 'csrf-token', 'HTTP_ACCEPT' => 'application/json'];

    public function testMyDataSavesItAsUnfinished(): void
    {
        $browser = self::createClient();
        $timeId = $this->unfinishedResult();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // A cell edited in place
        $browser->request('POST', '/en/my-data/cell/result/' . $timeId, ['column' => 'comment', 'from' => '', 'value' => 'Time ran out'], [], self::JSON);
        self::assertResponseIsSuccessful();
        self::assertSame(['pieces_placed' => 479, 'seconds_to_solve' => null, 'comment' => 'Time ran out'], $this->stored($timeId));

        // The row sheet
        $crawler = $browser->request('GET', '/en/my-data/result/' . $timeId);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('[data-testid="my-data-row-form"]');
        $fields = ['_token' => (string) $form->filter('input[name="_token"]')->attr('value'), 'comment' => 'Next time', 'day' => (string) $form->filter('input[name="day"]')->attr('value')];
        $this->submitSheet($browser, $timeId, $fields);

        self::assertSame(['pieces_placed' => 479, 'seconds_to_solve' => null, 'comment' => 'Next time'], $this->stored($timeId));
        self::assertSame(3600, self::getContainer()->get(Connection::class)->fetchOne('SELECT seconds_spent FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]));
    }

    /**
     * @param array<string, string> $fields
     */
    private function submitSheet(KernelBrowser $browser, string $timeId, array $fields): void
    {
        $browser->request('POST', '/en/my-data/result/' . $timeId, $fields, [], self::SAME_ORIGIN);

        self::assertLessThan(400, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());
    }

    private function unfinishedResult(): string
    {
        $timeId = Uuid::uuid7();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddUnfinishedPuzzleSolvingTime(
            timeId: $timeId,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleId: PuzzleFixture::PUZZLE_500_02,
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            piecesPlaced: 479,
            totalTime: null,
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
            'SELECT pieces_placed, seconds_to_solve, comment FROM puzzle_solving_time WHERE id = :id',
            ['id' => $timeId],
        );
        self::assertIsArray($row);

        return $row;
    }
}
