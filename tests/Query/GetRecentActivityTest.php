<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Nette\Utils\Json;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetRecentActivity;
use SpeedPuzzling\Web\Results\RecentActivityItem;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\ResultQueriesParityScenario;
use SpeedPuzzling\Web\Tests\TestingViewer;
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Value\SkillTier;
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
     * forPlayer() read the player's times with one `player_id = … OR team @> …` condition, which walked the
     * tracked_at index through the whole table for players with few times; it now reads the own times and the group
     * times apart, the newest `limit` of each. Every player, limit and viewer must get the same times in the same
     * order as with the single condition.
     */
    public function testForPlayerListsTheSameTimesAsTheSingleConditionQuery(): void
    {
        /** @var list<string> $playerIds */
        $playerIds = $this->database->fetchFirstColumn('SELECT id FROM player ORDER BY id');

        $groupTimeOfOthersSeen = false;
        $limitCutBothWays = false;

        // Anonymous, then a viewer hiding a group partner (the fixture block: PLAYER_REGULAR hides PLAYER_PRIVATE),
        // then one hiding a player that tracked group times (PLAYER_ADMIN hides PLAYER_REGULAR)
        $this->block(PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_REGULAR);

        foreach ([null, PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN] as $viewerId) {
            if ($viewerId === null) {
                TestingViewer::signOut(self::getContainer());
            } else {
                TestingViewer::signIn(self::getContainer(), $viewerId);
            }

            $hiddenPlayers = self::getContainer()->get(HiddenPlayers::class);
            $notHidden = $hiddenPlayers->sqlExclude('(CASE WHEN pst.team IS NULL THEN pst.player_id END)')
                . $hiddenPlayers->sqlExcludeTeam('pst.team');

            foreach ($playerIds as $playerId) {
                foreach ([1, 2, 3, 500] as $limit) {
                    /** @var list<array{id: string, tracked_at: string, player_id: string}> $expected */
                    $expected = $this->database->fetchAllAssociative(
                        <<<SQL
SELECT pst.id, pst.tracked_at, pst.player_id
FROM puzzle_solving_time pst
WHERE
    (pst.player_id = :playerId OR (pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID))))
    {$notHidden}
ORDER BY pst.tracked_at DESC
LIMIT :limit
SQL,
                        ['playerId' => $playerId, 'limit' => $limit],
                    );

                    $actual = $this->query->forPlayer($playerId, $limit);
                    $context = sprintf('player %s, limit %d, viewer %s', $playerId, $limit, $viewerId ?? 'anonymous');

                    // The tracked_at order is fixed; times tracked at the same moment may come in any order
                    self::assertSame(
                        array_map(static fn (array $row): string => (new DateTimeImmutable($row['tracked_at']))->format('Y-m-d H:i:s.u'), $expected),
                        array_map(static fn (RecentActivityItem $item): string => $item->trackedAt->format('Y-m-d H:i:s.u'), $actual),
                        $context,
                    );

                    if ($limit === 500) {
                        $expectedIds = array_column($expected, 'id');
                        $actualIds = $this->ids($actual);
                        sort($expectedIds);
                        sort($actualIds);
                        self::assertSame($expectedIds, $actualIds, $context);

                        $groupTimeOfOthersSeen = $groupTimeOfOthersSeen || in_array(
                            true,
                            array_map(static fn (array $row): bool => $row['player_id'] !== $playerId, $expected),
                            true,
                        );
                    }

                    if ($limit === 2) {
                        $own = $this->database->fetchOne('SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :playerId', ['playerId' => $playerId]);
                        assert(is_int($own));
                        $limitCutBothWays = $limitCutBothWays || ($own > $limit && count($expected) === $limit && in_array(
                            true,
                            array_map(static fn (array $row): bool => $row['player_id'] !== $playerId, $expected),
                            true,
                        ));
                    }
                }
            }
        }

        self::assertTrue($groupTimeOfOthersSeen, 'Fixtures must contain a group time tracked by somebody else');
        self::assertTrue($limitCutBothWays, 'Fixtures must contain a player whose newest times mix own and group times beyond the limit');
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
        pst.suspicious = false
        AND (
            pst.player_id = ANY(fpi.favorite_ids)
            OR (
                pst.team IS NOT NULL
                AND EXISTS (
                    SELECT 1
                    FROM jsonb_array_elements(pst.team::jsonb -> 'puzzlers') AS player_elem(player)
                    WHERE (player_elem.player ->> 'player_id')::UUID = ANY(fpi.favorite_ids)
                )
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

    public function testSuspiciousTimeIsOnlyInThePlayersOwnFeed(): void
    {
        // TIME_36 is PLAYER_REGULAR's (public profile), whom PLAYER_WITH_FAVORITES follows
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET suspicious = true WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_36],
        );

        self::assertNotContains(PuzzleSolvingTimeFixture::TIME_36, $this->ids($this->query->latest(200)));
        self::assertNotContains(PuzzleSolvingTimeFixture::TIME_36, $this->ids($this->query->ofPlayerFavorites(500, PlayerFixture::PLAYER_WITH_FAVORITES)));

        $suspiciousById = [];
        foreach ($this->query->forPlayer(PlayerFixture::PLAYER_REGULAR, 200) as $item) {
            $suspiciousById[$item->id] = $item->suspicious;
        }

        self::assertTrue($suspiciousById[PuzzleSolvingTimeFixture::TIME_36]);
        self::assertSame([PuzzleSolvingTimeFixture::TIME_36], array_keys(array_filter($suspiciousById)));
    }

    public function testBlockerDoesNotSeeTheBlockedPlayerInLatest(): void
    {
        $this->block(PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_REGULAR);

        self::assertTrue($this->showsPlayer($this->query->latest(500), PlayerFixture::PLAYER_REGULAR));

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertTrue($this->showsPlayer($this->query->latest(500), PlayerFixture::PLAYER_REGULAR));

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_ADMIN);
        $latest = $this->query->latest(500);
        self::assertNotEmpty($latest);
        self::assertFalse($this->showsPlayer($latest, PlayerFixture::PLAYER_REGULAR));
        self::assertFalse($this->showsPlayer($this->query->forPlayer(PlayerFixture::PLAYER_REGULAR, 500), PlayerFixture::PLAYER_REGULAR));
    }

    public function testGroupTimeWithAHiddenMemberIsDroppedFromTheSubjectsActivity(): void
    {
        // TIME_12 and TIME_41: tracked by PLAYER_REGULAR, PLAYER_PRIVATE is the partner
        $this->block(PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_PRIVATE);

        $ids = fn (): array => array_map(
            static fn (RecentActivityItem $item): string => $item->id,
            $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR, 500),
        );

        self::assertContains(PuzzleSolvingTimeFixture::TIME_12, $ids());

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_ADMIN);
        $filtered = $ids();
        self::assertNotContains(PuzzleSolvingTimeFixture::TIME_12, $filtered);
        self::assertNotContains(PuzzleSolvingTimeFixture::TIME_41, $filtered);
        // The partner's solo times go nowhere
        self::assertContains(PuzzleSolvingTimeFixture::TIME_01, $filtered);
    }

    public function testViewersOwnGroupTimeStaysEvenWhenAHiddenPlayerTrackedIt(): void
    {
        // The fixture block: PLAYER_REGULAR (who tracked TIME_12) hides the partner
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);
        $ownIds = array_map(
            static fn (RecentActivityItem $item): string => $item->id,
            $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR, 500),
        );
        self::assertContains(PuzzleSolvingTimeFixture::TIME_12, $ownIds);

        // The other way round: the partner hides the player who tracked the time
        $this->block(PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_REGULAR);
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_PRIVATE);

        $partnerItems = $this->query->forPlayer(PlayerFixture::PLAYER_PRIVATE, 500);
        $partnerIds = array_map(static fn (RecentActivityItem $item): string => $item->id, $partnerItems);
        self::assertContains(PuzzleSolvingTimeFixture::TIME_12, $partnerIds);
        self::assertContains(PuzzleSolvingTimeFixture::TIME_41, $partnerIds);

        foreach ($this->query->latest(500) as $item) {
            if ($item->playerId === PlayerFixture::PLAYER_REGULAR) {
                self::assertNotNull($item->players, 'A solo time of the hidden player is shown');
                self::assertTrue(Puzzler::listContainsPlayer($item->players, PlayerFixture::PLAYER_PRIVATE));
            }
        }
    }

    public function testFavoritesFeedLeavesOutAHiddenFavorite(): void
    {
        // PLAYER_WITH_FAVORITES follows PLAYER_REGULAR and PLAYER_ADMIN
        $unfiltered = $this->query->ofPlayerFavorites(500, PlayerFixture::PLAYER_WITH_FAVORITES);
        self::assertTrue($this->showsPlayer($unfiltered, PlayerFixture::PLAYER_REGULAR));

        $this->block(PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_REGULAR);
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);

        $feed = $this->query->ofPlayerFavorites(500, PlayerFixture::PLAYER_WITH_FAVORITES);
        self::assertFalse($this->showsPlayer($feed, PlayerFixture::PLAYER_REGULAR));
        self::assertTrue($this->showsPlayer($feed, PlayerFixture::PLAYER_ADMIN));
    }

    /**
     * forPlayer() and latest() were rewritten to cut their planning time (the brand, the skill tier and the event are
     * looked up after the limit). For every player, viewer and limit they must list what the previous statements -
     * kept below verbatim - listed, equal in every field.
     */
    public function testForPlayerAndLatestMatchThePreviousStatementsInEveryField(): void
    {
        ResultQueriesParityScenario::seed(self::getContainer());

        /** @var list<string> $playerIds */
        $playerIds = $this->database->fetchFirstColumn('SELECT id FROM player ORDER BY id');

        $seen = [
            'a skill tier' => false,
            'a member skill tier' => false,
            'a guest member' => false,
            'a private member unmasked by the allow list' => false,
            'an edition of a series' => false,
            'a series pick' => false,
            'a hidden picture' => false,
            'a suspicious time' => false,
        ];

        foreach (ResultQueriesParityScenario::viewers() as $viewerId) {
            if ($viewerId === null) {
                TestingViewer::signOut(self::getContainer());
            } else {
                TestingViewer::signIn(self::getContainer(), $viewerId);
            }

            foreach ([3, 500] as $limit) {
                $context = sprintf('latest, limit %d, viewer %s', $limit, $viewerId ?? 'anonymous');
                $latest = $this->query->latest($limit);
                $this->assertSameFeed($this->previousLatest($limit), $latest, $limit, $context);

                foreach ($playerIds as $playerId) {
                    $context = sprintf('player %s, limit %d, viewer %s', $playerId, $limit, $viewerId ?? 'anonymous');
                    $actual = $this->query->forPlayer($playerId, $limit);
                    $this->assertSameFeed($this->previousForPlayer($playerId, $limit), $actual, $limit, $context);

                    foreach ([...$actual, ...$latest] as $item) {
                        $seen['a skill tier'] = $seen['a skill tier'] || $item->skillTierName !== null;
                        $seen['an edition of a series'] = $seen['an edition of a series'] || ($item->competitionId !== null && $item->competitionSeriesName !== null);
                        $seen['a series pick'] = $seen['a series pick'] || ($item->competitionId === null && $item->competitionSeriesName !== null);
                        $seen['a hidden picture'] = $seen['a hidden picture'] || ($item->puzzleId === ResultQueriesParityScenario::HIDDEN_IMAGE_PUZZLE && $item->puzzleImage === null);
                        $seen['a suspicious time'] = $seen['a suspicious time'] || $item->suspicious;

                        foreach ($item->players ?? [] as $member) {
                            $seen['a guest member'] = $seen['a guest member'] || $member->playerId === null;
                            $seen['a member skill tier'] = $seen['a member skill tier'] || $member->skillTierName !== null;
                            $seen['a private member unmasked by the allow list'] = $seen['a private member unmasked by the allow list'] || (
                                $viewerId === PlayerFixture::PLAYER_WITH_FAVORITES && $member->playerId === PlayerFixture::PLAYER_PRIVATE && $member->isPrivate === false
                            );
                        }
                    }
                }
            }
        }

        foreach ($seen as $case => $wasSeen) {
            self::assertTrue($wasSeen, sprintf('The data must contain %s', $case));
        }
    }

    /**
     * player_skill is unique per player and pieces count; the day a second pieces count is computed, the feed must
     * not list every time once per count - the tier shown is the one at PlayerHeader::TIER_PIECES_COUNT pieces
     */
    public function testAnotherPiecesCountSkillDoesNotDuplicateFeedRows(): void
    {
        ResultQueriesParityScenario::skill($this->database, PlayerFixture::PLAYER_REGULAR, 3);
        ResultQueriesParityScenario::skill($this->database, PlayerFixture::PLAYER_REGULAR, 6, pieces: 1000);

        /** @var list<string> $expectedIds */
        $expectedIds = $this->database->fetchFirstColumn(
            "SELECT pst.id FROM puzzle_solving_time pst WHERE pst.player_id = :playerId OR (pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))",
            ['playerId' => PlayerFixture::PLAYER_REGULAR],
        );

        foreach (['forPlayer' => $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR, 500), 'latest' => $this->query->latest(500)] as $feed => $items) {
            $ids = $this->ids($items);
            self::assertSame(array_values(array_unique($ids)), $ids, $feed . ' lists a time twice');

            foreach ($items as $item) {
                if ($item->playerId === PlayerFixture::PLAYER_REGULAR) {
                    self::assertSame('proficient', $item->skillTierName, $feed);
                }
            }

            if ($feed === 'forPlayer') {
                sort($expectedIds);
                sort($ids);
                self::assertSame($expectedIds, $ids);
            }
        }
    }

    /**
     * @param list<RecentActivityItem> $expected
     * @param list<RecentActivityItem> $actual
     */
    private function assertSameFeed(array $expected, array $actual, int $limit, string $context): void
    {
        $trackedAt = static fn (RecentActivityItem $item): string => $item->trackedAt->format('Y-m-d H:i:s.u');

        // The tracked_at order is fixed; times tracked at the same moment may come in any order
        self::assertSame(array_map($trackedAt, $expected), array_map($trackedAt, $actual), $context);

        // A limit cutting through times tracked at the same moment may keep any of them
        $cut = count($expected) === $limit && $expected !== [] ? $trackedAt($expected[array_key_last($expected)]) : null;
        $comparable = static function (array $items) use ($trackedAt, $cut): array {
            $kept = array_values(array_filter($items, static fn (RecentActivityItem $item): bool => $trackedAt($item) !== $cut));
            usort($kept, static fn (RecentActivityItem $a, RecentActivityItem $b): int => [$trackedAt($b), $a->id] <=> [$trackedAt($a), $b->id]);

            return $kept;
        };

        self::assertEquals($comparable($expected), $comparable($actual), $context);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<RecentActivityItem>
     */
    private function items(array $rows): array
    {
        return array_map(static function (array $row): RecentActivityItem {
            /** @var array{time_id: string, player_id: string, player_name: null|string, player_code: string, player_country: null|string, puzzle_id: string, puzzle_name: string, manufacturer_name: string, puzzle_image: null|string, puzzle_image_ratio: null|string, time: null|int, pieces_count: int, comment: null|string, tracked_at: string, finished_puzzle_photo: null|string, team_id: null|string, puzzle_identification_number: null|string, finished_at: null|string, first_attempt: bool, unboxed: bool, suspicious?: bool, is_private: bool, competition_id: null|string, competition_name: null|string, competition_shortcut: null|string, competition_slug: null|string, competition_series_name: null|string, competition_series_shortcut: null|string, competition_series_slug: null|string, players: null|string, skill_tier: null|int, ranking_opted_out: bool} $row */
            $row['skill_tier_name'] = $row['skill_tier'] !== null
                ? strtolower(SkillTier::from((int) $row['skill_tier'])->name)
                : null;

            return RecentActivityItem::fromDatabaseRow($row);
        }, $rows);
    }

    private function notHiddenFragment(string $timeAlias): string
    {
        $hiddenPlayers = self::getContainer()->get(HiddenPlayers::class);

        return $hiddenPlayers->sqlExclude("(CASE WHEN {$timeAlias}.team IS NULL THEN {$timeAlias}.player_id END)")
            . $hiddenPlayers->sqlExcludeTeam("{$timeAlias}.team");
    }

    /**
     * The statement forPlayer() ran before the planning rewrite, verbatim
     *
     * @return list<RecentActivityItem>
     */
    private function previousForPlayer(string $playerId, int $limit): array
    {
        $privateProfileAccess = self::getContainer()->get(PrivateProfileAccess::class);
        $notHidden = $this->notHiddenFragment('puzzle_solving_time');

        $query = <<<SQL
WITH matched AS MATERIALIZED (
    (
        SELECT puzzle_solving_time.id
        FROM puzzle_solving_time
        WHERE puzzle_solving_time.player_id = :playerId
            {$notHidden}
        ORDER BY puzzle_solving_time.tracked_at DESC
        LIMIT :limit
    )
    UNION
    (
        SELECT puzzle_solving_time.id
        FROM puzzle_solving_time
        WHERE puzzle_solving_time.team IS NOT NULL
            AND (puzzle_solving_time.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
            {$notHidden}
        ORDER BY puzzle_solving_time.tracked_at DESC
        LIMIT :limit
    )
)
SELECT
    puzzle_solving_time.id as time_id,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image END AS puzzle_image,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image_ratio END AS puzzle_image_ratio,
    puzzle_solving_time.seconds_to_solve AS time,
    puzzle_solving_time.player_id AS player_id,
    player.name AS player_name,
    player.code AS player_code,
    player.country AS player_country,
    player.avatar AS player_avatar,
    puzzle.pieces_count,
    puzzle_solving_time.comment,
    manufacturer.name AS manufacturer_name,
    puzzle.identification_number AS puzzle_identification_number,
    puzzle_solving_time.tracked_at AS tracked_at,
    finished_at,
    puzzle_solving_time.finished_puzzle_photo AS finished_puzzle_photo,
    puzzle_solving_time.puzzling_team_id::varchar AS team_id,
    first_attempt,
    puzzle_solving_time.unboxed,
    puzzle_solving_time.suspicious,
    {$privateProfileAccess->sqlIsPrivate('player')} AS is_private,
    competition.id AS competition_id,
    competition.shortcut AS competition_shortcut,
    competition.name AS competition_name,
    competition.slug AS competition_slug,
    cs.name AS competition_series_name,
    cs.shortcut AS competition_series_shortcut,
    cs.slug AS competition_series_slug,
    ps.skill_tier,
    player.ranking_opted_out,
    CASE WHEN puzzle_solving_time.team IS NOT NULL THEN
        (SELECT JSON_AGG(JSON_BUILD_OBJECT(
            'player_id', elem.player ->> 'player_id',
            'player_name', COALESCE(p.name, elem.player ->> 'player_name'),
            'player_code', p.code,
            'player_country', p.country,
            'player_avatar', p.avatar,
            'is_private', {$privateProfileAccess->sqlIsPrivate('p')},
            'skill_tier', ps_m.skill_tier,
            'ranking_opted_out', COALESCE(p.ranking_opted_out, false)
        ) ORDER BY elem.ordinality)
        FROM json_array_elements(puzzle_solving_time.team -> 'puzzlers') WITH ORDINALITY AS elem(player, ordinality)
        LEFT JOIN player p ON p.id = (elem.player ->> 'player_id')::UUID
        LEFT JOIN player_skill ps_m ON ps_m.player_id = p.id)
    ELSE NULL END AS players
FROM matched
INNER JOIN puzzle_solving_time ON puzzle_solving_time.id = matched.id
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
INNER JOIN player ON puzzle_solving_time.player_id = player.id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
LEFT JOIN competition ON puzzle_solving_time.competition_id = competition.id
LEFT JOIN competition_series cs ON cs.id = COALESCE(competition.series_id, puzzle_solving_time.competition_series_id)
LEFT JOIN player_skill ps ON ps.player_id = player.id
ORDER BY puzzle_solving_time.tracked_at DESC
LIMIT :limit
SQL;

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->database->fetchAllAssociative($query, [
            'now' => self::getContainer()->get(ClockInterface::class)->now()->format('Y-m-d H:i:s'),
            'limit' => $limit,
            'playerId' => $playerId,
        ]);

        return $this->items($rows);
    }

    /**
     * The statement latest() ran before the planning rewrite, verbatim
     *
     * @return list<RecentActivityItem>
     */
    private function previousLatest(int $limit): array
    {
        $privateProfileAccess = self::getContainer()->get(PrivateProfileAccess::class);
        $notHidden = $this->notHiddenFragment('puzzle_solving_time');

        $query = <<<SQL
SELECT
    puzzle_solving_time.id as time_id,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image END AS puzzle_image,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image_ratio END AS puzzle_image_ratio,
    puzzle_solving_time.seconds_to_solve AS time,
    puzzle_solving_time.player_id AS player_id,
    player.name AS player_name,
    player.code AS player_code,
    player.country AS player_country,
    player.avatar AS player_avatar,
    puzzle.pieces_count,
    puzzle_solving_time.comment,
    manufacturer.name AS manufacturer_name,
    puzzle.identification_number AS puzzle_identification_number,
    puzzle_solving_time.tracked_at AS tracked_at,
    finished_at,
    puzzle_solving_time.finished_puzzle_photo AS finished_puzzle_photo,
    puzzle_solving_time.puzzling_team_id::varchar AS team_id,
    first_attempt,
    puzzle_solving_time.unboxed,
    {$privateProfileAccess->sqlIsPrivate('player')} AS is_private,
    competition.id AS competition_id,
    competition.shortcut AS competition_shortcut,
    competition.name AS competition_name,
    competition.slug AS competition_slug,
    cs.name AS competition_series_name,
    cs.shortcut AS competition_series_shortcut,
    cs.slug AS competition_series_slug,
    ps.skill_tier,
    player.ranking_opted_out,
    CASE WHEN puzzle_solving_time.team IS NOT NULL THEN
        (SELECT JSON_AGG(JSON_BUILD_OBJECT(
            'player_id', elem.player ->> 'player_id',
            'player_name', COALESCE(p.name, elem.player ->> 'player_name'),
            'player_code', p.code,
            'player_country', p.country,
            'player_avatar', p.avatar,
            'is_private', {$privateProfileAccess->sqlIsPrivate('p')},
            'skill_tier', ps_m.skill_tier,
            'ranking_opted_out', COALESCE(p.ranking_opted_out, false)
        ) ORDER BY elem.ordinality)
        FROM json_array_elements(puzzle_solving_time.team -> 'puzzlers') WITH ORDINALITY AS elem(player, ordinality)
        LEFT JOIN player p ON p.id = (elem.player ->> 'player_id')::UUID
        LEFT JOIN player_skill ps_m ON ps_m.player_id = p.id)
    ELSE NULL END AS players
FROM puzzle_solving_time
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
INNER JOIN player ON puzzle_solving_time.player_id = player.id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
LEFT JOIN competition ON puzzle_solving_time.competition_id = competition.id
LEFT JOIN competition_series cs ON cs.id = COALESCE(competition.series_id, puzzle_solving_time.competition_series_id)
LEFT JOIN player_skill ps ON ps.player_id = player.id
WHERE {$privateProfileAccess->sqlIsPublic('player')}
    AND puzzle_solving_time.suspicious = false
    {$notHidden}
ORDER BY puzzle_solving_time.tracked_at DESC
LIMIT :limit
SQL;

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->database->fetchAllAssociative($query, [
            'now' => self::getContainer()->get(ClockInterface::class)->now()->format('Y-m-d H:i:s'),
            'limit' => $limit,
        ]);

        return $this->items($rows);
    }

    /**
     * @param array<RecentActivityItem> $items
     * @return list<string>
     */
    private function ids(array $items): array
    {
        return array_values(array_map(static fn (RecentActivityItem $item): string => $item->id, $items));
    }

    /**
     * @param array<RecentActivityItem> $items
     */
    private function showsPlayer(array $items, string $playerId): bool
    {
        foreach ($items as $item) {
            if ($item->playerId === $playerId || Puzzler::listContainsPlayer($item->players, $playerId)) {
                return true;
            }
        }

        return false;
    }

    private function block(string $blockerId, string $blockedId): void
    {
        $this->database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, " . TestClock::SQL_NOW . ", 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }
}
