<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetCompetitionPermissions;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * GetCompetitionPermissions replaced a per-id maintainer query (edit voters) and
 * a per-id entity load (delete voters). Its answers are checked against both of
 * them for every player x competition/series combination.
 */
final class GetCompetitionPermissionsTest extends KernelTestCase
{
    private const array PLAYERS = [
        PlayerFixture::PLAYER_REGULAR,
        PlayerFixture::PLAYER_PRIVATE,
        PlayerFixture::PLAYER_ADMIN,
        PlayerFixture::PLAYER_WITH_FAVORITES,
        PlayerFixture::PLAYER_WITH_STRIPE,
    ];

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $this->connection = $connection;

        // Every way to earn a permission, on top of the fixtures (where the regular
        // player created and maintains two competitions and the admin every series)
        $this->connection->insert('competition_maintainer', [
            'competition_id' => CompetitionFixture::COMPETITION_WJPC_2024,
            'player_id' => PlayerFixture::PLAYER_WITH_FAVORITES,
        ]);
        $this->connection->insert('competition_series_maintainer', [
            'competition_series_id' => CompetitionSeriesFixture::SERIES_OFFLINE,
            'player_id' => PlayerFixture::PLAYER_WITH_FAVORITES,
        ]);
        $this->connection->update(
            'competition_series',
            ['added_by_player_id' => PlayerFixture::PLAYER_WITH_STRIPE],
            ['id' => CompetitionSeriesFixture::SERIES_PAST_ONLY],
        );
    }

    public function testAnswersMatchThePerIdChecksTheyReplaced(): void
    {
        $query = $this->query();

        /** @var list<string> $competitionIds */
        $competitionIds = $this->connection->fetchFirstColumn('SELECT id FROM competition');
        /** @var list<string> $seriesIds */
        $seriesIds = $this->connection->fetchFirstColumn('SELECT id FROM competition_series');

        self::assertNotEmpty($competitionIds);
        self::assertNotEmpty($seriesIds);

        foreach (self::PLAYERS as $playerId) {
            $permissions = $query->forPlayer($playerId);

            foreach ($competitionIds as $competitionId) {
                self::assertSame(
                    $this->previousCompetitionMaintainerCheck($competitionId, $playerId),
                    $permissions->canEditCompetition($competitionId),
                    sprintf('edit competition %s as %s', $competitionId, $playerId),
                );
                self::assertSame(
                    $this->previousCompetitionDeleteCheck($competitionId, $playerId),
                    $permissions->canDeleteCompetition($competitionId),
                    sprintf('delete competition %s as %s', $competitionId, $playerId),
                );
            }

            foreach ($seriesIds as $seriesId) {
                self::assertSame(
                    $this->previousSeriesMaintainerCheck($seriesId, $playerId),
                    $permissions->canEditSeries($seriesId),
                    sprintf('edit series %s as %s', $seriesId, $playerId),
                );
                self::assertSame(
                    $this->previousSeriesDeleteCheck($seriesId, $playerId),
                    $permissions->canDeleteSeries($seriesId),
                    sprintf('delete series %s as %s', $seriesId, $playerId),
                );
            }
        }
    }

    public function testEveryRouteToAPermission(): void
    {
        $regular = $this->query()->forPlayer(PlayerFixture::PLAYER_REGULAR);
        // created it
        self::assertTrue($regular->canEditCompetition(CompetitionFixture::COMPETITION_UNAPPROVED));
        self::assertTrue($regular->canDeleteCompetition(CompetitionFixture::COMPETITION_UNAPPROVED));
        self::assertFalse($regular->canEditCompetition(CompetitionFixture::COMPETITION_WJPC_2024));

        $favorites = $this->query()->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES);
        // competition maintainer: may edit, not delete
        self::assertTrue($favorites->canEditCompetition(CompetitionFixture::COMPETITION_WJPC_2024));
        self::assertFalse($favorites->canDeleteCompetition(CompetitionFixture::COMPETITION_WJPC_2024));
        // series maintainer: may edit the series and its editions, delete neither
        self::assertTrue($favorites->canEditSeries(CompetitionSeriesFixture::SERIES_OFFLINE));
        self::assertFalse($favorites->canDeleteSeries(CompetitionSeriesFixture::SERIES_OFFLINE));
        self::assertTrue($favorites->canEditCompetition(CompetitionSeriesFixture::EDITION_OFFLINE_1));
        self::assertFalse($favorites->canDeleteCompetition(CompetitionSeriesFixture::EDITION_OFFLINE_1));

        $stripe = $this->query()->forPlayer(PlayerFixture::PLAYER_WITH_STRIPE);
        // series creator: may edit and delete the series and its editions
        self::assertTrue($stripe->canEditSeries(CompetitionSeriesFixture::SERIES_PAST_ONLY));
        self::assertTrue($stripe->canDeleteSeries(CompetitionSeriesFixture::SERIES_PAST_ONLY));
        self::assertTrue($stripe->canEditCompetition(CompetitionSeriesFixture::EDITION_PAST_ONLY_1));
        self::assertTrue($stripe->canDeleteCompetition(CompetitionSeriesFixture::EDITION_PAST_ONLY_1));
        self::assertFalse($stripe->canEditSeries(CompetitionSeriesFixture::SERIES_OFFLINE));
    }

    public function testIdsAreMatchedCaseInsensitivelyLikePostgresUuids(): void
    {
        $permissions = $this->query()->forPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertTrue($permissions->canEditCompetition(strtoupper(CompetitionFixture::COMPETITION_UNAPPROVED)));
        self::assertFalse($permissions->canEditCompetition('not-a-uuid'));
    }

    public function testOneQueryPerPlayerPerRequest(): void
    {
        $query = $this->query();

        self::assertSame($query->forPlayer(PlayerFixture::PLAYER_REGULAR), $query->forPlayer(PlayerFixture::PLAYER_REGULAR));

        $first = $query->forPlayer(PlayerFixture::PLAYER_REGULAR);
        $query->reset();
        self::assertNotSame($first, $query->forPlayer(PlayerFixture::PLAYER_REGULAR));
    }

    private function query(): GetCompetitionPermissions
    {
        /** @var GetCompetitionPermissions $query */
        $query = self::getContainer()->get(GetCompetitionPermissions::class);

        return $query;
    }

    /**
     * The query IsCompetitionMaintainer ran per competition before.
     */
    private function previousCompetitionMaintainerCheck(string $competitionId, string $playerId): bool
    {
        return $this->connection->fetchOne(<<<SQL
SELECT 1 FROM (
    SELECT added_by_player_id AS player_id FROM competition WHERE id = :competitionId AND added_by_player_id = :playerId
    UNION
    SELECT player_id FROM competition_maintainer WHERE competition_id = :competitionId AND player_id = :playerId
    UNION
    SELECT cs.added_by_player_id AS player_id FROM competition_series cs INNER JOIN competition c ON c.series_id = cs.id WHERE c.id = :competitionId AND cs.added_by_player_id = :playerId
    UNION
    SELECT csm.player_id FROM competition_series_maintainer csm INNER JOIN competition c ON c.series_id = csm.competition_series_id WHERE c.id = :competitionId AND csm.player_id = :playerId
) sub
LIMIT 1
SQL, ['competitionId' => $competitionId, 'playerId' => $playerId]) !== false;
    }

    /**
     * The query IsCompetitionSeriesMaintainer ran per series before.
     */
    private function previousSeriesMaintainerCheck(string $seriesId, string $playerId): bool
    {
        return $this->connection->fetchOne(<<<SQL
SELECT 1 FROM (
    SELECT added_by_player_id AS player_id FROM competition_series WHERE id = :seriesId AND added_by_player_id = :playerId
    UNION
    SELECT player_id FROM competition_series_maintainer WHERE competition_series_id = :seriesId AND player_id = :playerId
) sub
LIMIT 1
SQL, ['seriesId' => $seriesId, 'playerId' => $playerId]) !== false;
    }

    /**
     * What CompetitionDeleteVoter decided from the loaded entity before.
     */
    private function previousCompetitionDeleteCheck(string $competitionId, string $playerId): bool
    {
        $competition = self::getContainer()->get(CompetitionRepository::class)->get($competitionId);

        if ($competition->addedByPlayer !== null && $competition->addedByPlayer->id->toString() === $playerId) {
            return true;
        }

        return $competition->series !== null
            && $competition->series->addedByPlayer !== null
            && $competition->series->addedByPlayer->id->toString() === $playerId;
    }

    /**
     * What CompetitionSeriesDeleteVoter decided from the loaded entity before.
     */
    private function previousSeriesDeleteCheck(string $seriesId, string $playerId): bool
    {
        $series = self::getContainer()->get(CompetitionSeriesRepository::class)->get($seriesId);

        return $series->addedByPlayer !== null
            && $series->addedByPlayer->id->toString() === $playerId;
    }
}
