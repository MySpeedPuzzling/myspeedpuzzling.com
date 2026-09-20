<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Filtering by pair/team on the puzzle page and on a profile (docs/features/pairs-and-teams/README.md).
 * Both are free for every signed-in player.
 */
final class PairsAndTeamsFiltersTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    public function testPuzzlePageShowsOnlyThePairsTheViewerTookPartIn(): void
    {
        // PLAYER_WITH_FAVORITES has no membership: the switch is free
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_FAVORITES);

        // PUZZLE_1000_01 already has the fixture pair's time (TIME_12); add one of the viewer's own
        $this->addPairTime('auth0|fav004', ['#admin'], PuzzleFixture::PUZZLE_1000_01);

        $component = $this->createLiveComponent('PuzzleTimes', [
            'puzzleId' => PuzzleFixture::PUZZLE_1000_01,
            'piecesCount' => 1000,
            'category' => 'duo',
        ], $client);
        $component->setRouteLocale('en');

        $crawler = $component->render()->crawler();
        self::assertCount(2, $crawler->filter('[data-testid="team-link"]'));
        self::assertCount(1, $crawler->filter('#only-my-teams'), 'The switch is offered to a signed-in player without membership');
        self::assertStringContainsString('My pairs only', $crawler->text());

        $component->set('onlyMyTeams', true);

        $crawler = $component->render()->crawler();
        $links = $crawler->filter('[data-testid="team-link"]');
        self::assertCount(1, $links);
        self::assertStringContainsString('Admin User', $crawler->filter('tr.table-active-player')->text());
    }

    public function testGuestIsNotOfferedTheSwitch(): void
    {
        $client = self::createClient();

        $component = $this->createLiveComponent('PuzzleTimes', [
            'puzzleId' => PuzzleFixture::PUZZLE_1000_01,
            'piecesCount' => 1000,
            'category' => 'duo',
        ], $client);
        $component->setRouteLocale('en');

        self::assertCount(0, $component->render()->crawler()->filter('#only-my-teams'));
    }

    public function testProfileListsCanBeNarrowedToOnePair(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_FAVORITES);

        $this->addPairTime('auth0|fav004', ['#admin'], PuzzleFixture::PUZZLE_1500_01);
        $this->addPairTime('auth0|fav004', ['#admin'], PuzzleFixture::PUZZLE_1500_02);
        $otherPairTeamId = $this->addPairTime('auth0|fav004', ['Grandma'], PuzzleFixture::PUZZLE_2000);

        $component = $this->createLiveComponent('PlayerSolvedPuzzles', [
            'playerId' => PlayerFixture::PLAYER_WITH_FAVORITES,
        ], $client);
        $component->setRouteLocale('en');

        $crawler = $component->render()->crawler();
        $options = $crawler->filter('select[data-model="team"] option');
        // "All" + the two pairs, the one with more results first, each named by the other person
        self::assertCount(3, $options);
        self::assertStringContainsString('Admin User (2)', $options->eq(1)->text());
        self::assertStringContainsString('Grandma (1)', $options->eq(2)->text());

        $component->set('category', 'duo');
        $component->set('team', $otherPairTeamId);

        $html = $component->render()->toString();
        self::assertStringContainsString('Grandma', $html);
        self::assertStringNotContainsString(PuzzleFixture::PUZZLE_1500_01, $html);
        self::assertStringContainsString(PuzzleFixture::PUZZLE_2000, $html);
    }

    /**
     * @param array<string> $groupPlayers
     */
    private function addPairTime(string $userId, array $groupPlayers, string $puzzleId): string
    {
        $timeId = Uuid::uuid7();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: $userId,
            puzzleId: $puzzleId,
            competitionId: null,
            time: '05:00:00',
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: $groupPlayers,
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
        ));

        $teamId = self::getContainer()->get(Connection::class)->fetchOne('SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId->toString()]);
        self::assertIsString($teamId);

        return $teamId;
    }
}
