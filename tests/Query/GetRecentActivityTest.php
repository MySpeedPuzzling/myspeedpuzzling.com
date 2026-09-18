<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Nette\Utils\Json;
use SpeedPuzzling\Web\Query\GetRecentActivity;
use SpeedPuzzling\Web\Results\RecentActivityItem;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetRecentActivityTest extends KernelTestCase
{
    private GetRecentActivity $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(GetRecentActivity::class);
        $this->database = $container->get(Connection::class);
    }

    public function testLatestExposesSeriesOfEditionAndKeepsStandaloneShape(): void
    {
        // TIME_36 (PLAYER_REGULAR, public profile, no competition) is pointed at an EJJ edition;
        // TIME_09 stays linked to the standalone WJPC 2024.
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET competition_id = :competitionId WHERE id = :timeId',
            ['competitionId' => CompetitionSeriesFixture::EDITION_EJJ_68, 'timeId' => PuzzleSolvingTimeFixture::TIME_36],
        );

        $byTimeId = [];
        foreach ($this->query->latest(200) as $item) {
            $byTimeId[$item->id] = $item;
        }

        $edition = $byTimeId[PuzzleSolvingTimeFixture::TIME_36];
        self::assertSame('EJJ #68 — February 2026', $edition->competitionName);
        self::assertSame('ejj-68-february-2026', $edition->competitionSlug);
        self::assertSame('Euro Jigsaw Jam', $edition->competitionSeriesName);
        self::assertSame('euro-jigsaw-jam-series', $edition->competitionSeriesSlug);
        self::assertNull($edition->competitionSeriesShortcut);

        $standalone = $byTimeId[PuzzleSolvingTimeFixture::TIME_09];
        self::assertSame('WJPC 2024', $standalone->competitionName);
        self::assertSame('WJPC24', $standalone->competitionShortcut);
        self::assertSame('wjpc-2024', $standalone->competitionSlug);
        self::assertNull($standalone->competitionSeriesName);
        self::assertNull($standalone->competitionSeriesShortcut);
        self::assertNull($standalone->competitionSeriesSlug);
    }

    public function testForPlayerExposesSeriesOfEdition(): void
    {
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET competition_id = :competitionId WHERE id = :timeId',
            ['competitionId' => CompetitionSeriesFixture::EDITION_EJJ_68, 'timeId' => PuzzleSolvingTimeFixture::TIME_36],
        );

        $byTimeId = [];
        foreach ($this->query->forPlayer(PlayerFixture::PLAYER_REGULAR, 200) as $item) {
            $byTimeId[$item->id] = $item;
        }

        $edition = $byTimeId[PuzzleSolvingTimeFixture::TIME_36];
        self::assertSame('Euro Jigsaw Jam', $edition->competitionSeriesName);
        self::assertSame('euro-jigsaw-jam-series', $edition->competitionSeriesSlug);
        self::assertSame('ejj-68-february-2026', $edition->competitionSlug);
    }

    /**
     * ofPlayerFavorites() matched team times with an EXISTS over jsonb_array_elements(), which no
     * index can answer, and read the favorites inside the query; it now reads them first and tests
     * team membership by jsonb containment. Every set of favorites must list exactly the same times.
     */
    public function testFavoritesFeedMatchesThePreviousQuery(): void
    {
        $viewerId = PlayerFixture::PLAYER_WITH_FAVORITES;

        /** @var list<string> $playerIds */
        $playerIds = $this->database->fetchFirstColumn('SELECT id FROM player ORDER BY id');

        $favoriteSets = [[], $playerIds];
        foreach ($playerIds as $playerId) {
            $favoriteSets[] = [$playerId];
        }

        $timesOfTeamMemberSeen = false;
        $othersTeamTimeSkipped = false;

        foreach ($favoriteSets as $favoritePlayerIds) {
            $this->database->executeStatement(
                'UPDATE player SET favorite_players = :favorites WHERE id = :viewerId',
                ['favorites' => Json::encode($favoritePlayerIds), 'viewerId' => $viewerId],
            );

            /** @var list<string> $expected */
            $expected = $this->database->fetchFirstColumn(
                <<<'SQL'
WITH favorite_player_ids_array AS (
    SELECT array_agg(fav_players.player_id::UUID) AS favorite_ids
    FROM player
    CROSS JOIN LATERAL json_array_elements_text(favorite_players) AS fav_players(player_id)
    WHERE id = :playerId
),
filtered_puzzle_solving_time AS (
    SELECT
        pst.id
    FROM
        puzzle_solving_time pst, favorite_player_ids_array fpi
    WHERE
        pst.player_id = ANY(fpi.favorite_ids)
        OR (
            pst.team IS NOT NULL
            AND EXISTS (
                SELECT 1
                FROM jsonb_array_elements(pst.team::jsonb -> 'puzzlers') AS player_elem(player)
                WHERE (player_elem.player ->> 'player_id')::UUID = ANY(fpi.favorite_ids)
            )
        )
    ORDER BY pst.tracked_at DESC
    LIMIT :limit
)
SELECT pst.id
FROM filtered_puzzle_solving_time fpt
INNER JOIN puzzle_solving_time pst ON pst.id = fpt.id
INNER JOIN player ON pst.player_id = player.id
WHERE player.is_private = false
SQL,
                ['playerId' => $viewerId, 'limit' => 500],
            );
            /** @var list<string> $ownPublicTimes */
            $ownPublicTimes = $this->database->fetchFirstColumn(
                'SELECT pst.id FROM puzzle_solving_time pst INNER JOIN player ON player.id = pst.player_id WHERE player.is_private = false AND pst.player_id IN (:favorites)',
                ['favorites' => $favoritePlayerIds],
                ['favorites' => ArrayParameterType::STRING],
            );
            /** @var list<string> $publicTeamTimes */
            $publicTeamTimes = $this->database->fetchFirstColumn(
                'SELECT pst.id FROM puzzle_solving_time pst INNER JOIN player ON player.id = pst.player_id WHERE player.is_private = false AND pst.team IS NOT NULL',
            );

            $actual = array_map(
                static fn (RecentActivityItem $item): string => $item->id,
                $this->query->ofPlayerFavorites(500, $viewerId),
            );

            sort($expected);
            sort($actual);
            self::assertSame($expected, $actual, sprintf('favorites %s', implode(', ', $favoritePlayerIds)));

            $timesOfTeamMemberSeen = $timesOfTeamMemberSeen || array_diff($expected, $ownPublicTimes) !== [];
            $othersTeamTimeSkipped = $othersTeamTimeSkipped || ($favoritePlayerIds !== [] && array_diff($publicTeamTimes, $expected) !== []);
        }

        self::assertTrue($timesOfTeamMemberSeen, 'Fixtures must contain a time listed only because a favorite was in its team');
        self::assertTrue($othersTeamTimeSkipped, 'Fixtures must contain a team time no favorite was part of');
    }
}
