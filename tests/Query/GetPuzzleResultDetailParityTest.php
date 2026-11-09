<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\PuzzleResultNotFound;
use SpeedPuzzling\Web\Query\GetPuzzleResultDetail;
use SpeedPuzzling\Web\Results\PuzzleResultAttempt;
use SpeedPuzzling\Web\Results\PuzzleResultDetail;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\ResultQueriesParityScenario;
use SpeedPuzzling\Web\Tests\TestingViewer;
use SpeedPuzzling\Web\Value\Puzzler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * GetPuzzleResultDetail::byTimeId() was rewritten to cut its planning time (docs/features/puzzle-result-detail.md
 * "Query shape"). Every time of the test database, for every kind of viewer, must give what the previous statement -
 * kept here verbatim - gave: found or not, the same attempts in the same order and the same values in every field.
 */
final class GetPuzzleResultDetailParityTest extends KernelTestCase
{
    private GetPuzzleResultDetail $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetPuzzleResultDetail::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testEveryTimeMatchesThePreviousStatementForEveryViewer(): void
    {
        $seeded = ResultQueriesParityScenario::seed(self::getContainer());

        /** @var list<string> $timeIds */
        $timeIds = $this->database->fetchFirstColumn('SELECT id FROM puzzle_solving_time ORDER BY id');

        $seen = [
            'solo' => false,
            'duo' => false,
            'team' => false,
            'several attempts of a team' => false,
            'a guest member' => false,
            'hidden by a block' => false,
            'hidden as private' => false,
            'unmasked by the allow list' => false,
            'a skill tier' => false,
            'a member skill tier' => false,
            'an edition of a series' => false,
            'a series pick' => false,
            'a hidden picture' => false,
            'a puzzle without a brand' => false,
        ];

        foreach (ResultQueriesParityScenario::viewers() as $viewerId) {
            if ($viewerId === null) {
                TestingViewer::signOut(self::getContainer());
            } else {
                TestingViewer::signIn(self::getContainer(), $viewerId);
            }

            foreach ($timeIds as $timeId) {
                $context = sprintf('time %s, viewer %s', $timeId, $viewerId ?? 'anonymous');
                $rows = $this->previousStatement($timeId);
                $expected = $this->expectedFrom($rows, $timeId, $viewerId);

                try {
                    $actual = $this->query->byTimeId($timeId, $viewerId);
                } catch (PuzzleResultNotFound) {
                    $actual = null;
                }

                if ($expected === null) {
                    self::assertNull($actual, $context);

                    if ($rows === [] && $this->timeHasBrand($timeId) === false) {
                        $seen['a puzzle without a brand'] = true;
                    } elseif ($rows === [] || $this->rowOf($rows, $timeId) === null) {
                        $seen['hidden by a block'] = true;
                    } else {
                        $seen['hidden as private'] = true;
                    }

                    continue;
                }

                self::assertNotNull($actual, $context);
                $this->assertSameDetail($expected, $actual, $context);

                $seen[$actual->puzzlingType] = true;
                $seen['several attempts of a team'] = $seen['several attempts of a team'] || ($actual->puzzlingType === 'team' && count($actual->attempts) > 1);
                $seen['a skill tier'] = $seen['a skill tier'] || $actual->player->skillTierName !== null;
                $seen['a hidden picture'] = $seen['a hidden picture'] || ($actual->puzzleId === ResultQueriesParityScenario::HIDDEN_IMAGE_PUZZLE && $actual->puzzleImage === null);

                foreach ($actual->players ?? [] as $member) {
                    $seen['a guest member'] = $seen['a guest member'] || $member->playerId === null;
                    $seen['a member skill tier'] = $seen['a member skill tier'] || $member->skillTierName !== null;
                    $seen['unmasked by the allow list'] = $seen['unmasked by the allow list'] || (
                        $viewerId === PlayerFixture::PLAYER_WITH_FAVORITES && $member->playerId === PlayerFixture::PLAYER_PRIVATE && $member->isPrivate === false
                    );
                }

                foreach ($actual->attempts as $attempt) {
                    $seen['an edition of a series'] = $seen['an edition of a series'] || ($attempt->competitionId !== null && $attempt->competitionSeriesName !== null);
                    $seen['a series pick'] = $seen['a series pick'] || ($attempt->timeId === $seeded['series_pick_time'] && $attempt->competitionId === null && $attempt->competitionSeriesName !== null);
                }
            }
        }

        foreach ($seen as $case => $wasSeen) {
            self::assertTrue($wasSeen, sprintf('The data must contain %s', $case));
        }
    }

    private function assertSameDetail(PuzzleResultDetail $expected, PuzzleResultDetail $actual, string $context): void
    {
        self::assertSame($expected->focusTimeId, $actual->focusTimeId, $context);
        self::assertSame($expected->puzzleId, $actual->puzzleId, $context);
        self::assertSame($expected->puzzleName, $actual->puzzleName, $context);
        self::assertSame($expected->puzzleImage, $actual->puzzleImage, $context);
        self::assertSame($expected->puzzleImageRatio, $actual->puzzleImageRatio, $context);
        self::assertSame($expected->piecesCount, $actual->piecesCount, $context);
        self::assertSame($expected->manufacturerName, $actual->manufacturerName, $context);
        self::assertSame($expected->puzzlingType, $actual->puzzlingType, $context);
        self::assertSame($expected->teamId, $actual->teamId, $context);
        self::assertSame($expected->teamName, $actual->teamName, $context);
        self::assertEquals($expected->player, $actual->player, $context);
        self::assertEquals($expected->players, $actual->players, $context);

        $orderKey = static fn (PuzzleResultAttempt $attempt): string => ($attempt->finishedAt ?? $attempt->trackedAt)->format('Y-m-d H:i:s.u') . '|' . $attempt->trackedAt->format('Y-m-d H:i:s.u');

        // The order is fixed by (finished, tracked); attempts equal in both may come in any order
        self::assertSame(array_map($orderKey, $expected->attempts), array_map($orderKey, $actual->attempts), $context);

        $normalised = static function (array $attempts) use ($orderKey): array {
            $plain = array_map(
                static fn (PuzzleResultAttempt $attempt): PuzzleResultAttempt => $attempt->withComparison(isBest: false, deltaToPrevious: null, gapToBest: null),
                $attempts,
            );
            usort($plain, static fn (PuzzleResultAttempt $a, PuzzleResultAttempt $b): int => [$orderKey($b), $a->timeId] <=> [$orderKey($a), $b->timeId]);

            return $plain;
        };

        self::assertEquals($normalised($expected->attempts), $normalised($actual->attempts), $context);
    }

    /**
     * What byTimeId() made of the previous statement's rows (without the comparisons between attempts, which are
     * computed from these values by the same code, and without the standing - its own, unchanged statement)
     *
     * @param list<array<string, mixed>> $rows
     */
    private function expectedFrom(array $rows, string $timeId, null|string $viewerId): null|PuzzleResultDetail
    {
        $focusRow = $this->rowOf($rows, $timeId);

        if ($focusRow === null) {
            return null;
        }

        /** @var array{player_id: string, player_name: null|string, player_code: null|string, player_country: null|string, player_avatar: null|string, is_private: null|bool, skill_tier: null|int, ranking_opted_out: bool} $focusRow */
        $player = Puzzler::fromDatabaseRow($focusRow);
        $players = is_string($focusRow['players'] ?? null) ? array_values(Puzzler::createPuzzlersFromJson($focusRow['players'])) : null;

        $memberPlayerIds = [];
        foreach ($players ?? [] as $member) {
            if ($member->playerId !== null) {
                $memberPlayerIds[] = $member->playerId;
            }
        }

        $viewerIsSubject = $viewerId !== null && (
            $players === null ? $viewerId === $player->playerId : in_array($viewerId, $memberPlayerIds, true)
        );

        $isPrivate = $player->isPrivate;
        if ($players !== null) {
            $isPrivate = true;
            foreach ($players as $member) {
                if ($member->playerId === null || $member->isPrivate === false) {
                    $isPrivate = false;
                }
            }
        }

        if ($viewerIsSubject === false && $isPrivate === true) {
            return null;
        }

        $attempts = [];
        foreach ($rows as $row) {
            /** @var array{time_id: string, tracked_by_id: string, time: null|int, finished_at: null|string, tracked_at: string, first_attempt: bool, unboxed: bool, suspicious: bool, comment: null|string, finished_puzzle_photo: null|string, competition_id: null|string, competition_shortcut: null|string, competition_name: null|string, competition_slug: null|string, competition_series_name: null|string, competition_series_shortcut: null|string, competition_series_slug: null|string} $row */
            $attempts[] = new PuzzleResultAttempt(
                timeId: $row['time_id'],
                trackedByPlayerId: $row['tracked_by_id'],
                time: $row['time'],
                finishedAt: $row['finished_at'] !== null ? new DateTimeImmutable($row['finished_at']) : null,
                trackedAt: new DateTimeImmutable($row['tracked_at']),
                firstAttempt: $row['first_attempt'],
                unboxed: $row['unboxed'],
                suspicious: $row['suspicious'],
                comment: $row['comment'],
                finishedPuzzlePhoto: $row['finished_puzzle_photo'],
                competitionId: $row['competition_id'],
                competitionShortcut: $row['competition_shortcut'],
                competitionName: $row['competition_name'],
                competitionSlug: $row['competition_slug'],
                competitionSeriesName: $row['competition_series_name'],
                competitionSeriesShortcut: $row['competition_series_shortcut'],
                competitionSeriesSlug: $row['competition_series_slug'],
                memberPlayerIds: $memberPlayerIds,
            );
        }

        /** @var array{puzzle_id: string, puzzle_name: string, puzzle_image: null|string, puzzle_image_ratio: null|string, pieces_count: int, manufacturer_name: string, puzzling_type: string, team_id: null|string, team_name: null|string} $focusRow */
        return new PuzzleResultDetail(
            focusTimeId: $timeId,
            puzzleId: $focusRow['puzzle_id'],
            puzzleName: $focusRow['puzzle_name'],
            puzzleImage: $focusRow['puzzle_image'],
            puzzleImageRatio: $focusRow['puzzle_image_ratio'] !== null ? (float) $focusRow['puzzle_image_ratio'] : null,
            piecesCount: $focusRow['pieces_count'],
            manufacturerName: $focusRow['manufacturer_name'],
            puzzlingType: $focusRow['puzzling_type'],
            player: $player,
            teamId: $players !== null ? $focusRow['team_id'] : null,
            teamName: $players !== null ? $focusRow['team_name'] : null,
            players: $players,
            attempts: $attempts,
            bestAttempt: null,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return null|array<string, mixed>
     */
    private function rowOf(array $rows, string $timeId): null|array
    {
        foreach ($rows as $row) {
            if ($row['time_id'] === $timeId) {
                return $row;
            }
        }

        return null;
    }

    private function timeHasBrand(string $timeId): bool
    {
        return $this->database->fetchOne(
            'SELECT puzzle.manufacturer_id IS NOT NULL FROM puzzle_solving_time pst INNER JOIN puzzle ON puzzle.id = pst.puzzle_id WHERE pst.id = :id',
            ['id' => $timeId],
        ) === true;
    }

    /**
     * The statement byTimeId() ran before the planning rewrite, verbatim
     *
     * @return list<array<string, mixed>>
     */
    private function previousStatement(string $timeId): array
    {
        $privateProfileAccess = self::getContainer()->get(PrivateProfileAccess::class);
        $hiddenPlayers = self::getContainer()->get(HiddenPlayers::class);

        $notHidden = $hiddenPlayers->sqlExclude('(CASE WHEN pst.team IS NULL THEN pst.player_id END)')
            . $hiddenPlayers->sqlExcludeTeam('pst.team');

        $query = <<<SQL
WITH focus AS (
    SELECT id, puzzle_id, player_id, puzzling_type, puzzling_team_id, team
    FROM puzzle_solving_time
    WHERE id = :timeId
),
focus_members AS (
    SELECT JSON_AGG(
        JSON_BUILD_OBJECT(
            'player_id', member.player ->> 'player_id',
            'player_name', COALESCE(p.name, member.player ->> 'player_name'),
            'player_code', p.code,
            'player_country', p.country,
            'player_avatar', p.avatar,
            'is_private', {$privateProfileAccess->sqlIsPrivate('p')},
            'skill_tier', ps_member.skill_tier,
            'ranking_opted_out', COALESCE(p.ranking_opted_out, false)
        ) ORDER BY member.ordinality
    ) AS players
    FROM focus
        CROSS JOIN LATERAL json_array_elements(focus.team -> 'puzzlers') WITH ORDINALITY AS member(player, ordinality)
        LEFT JOIN player p ON p.id = (member.player ->> 'player_id')::UUID
        LEFT JOIN player_skill ps_member ON ps_member.player_id = p.id
    WHERE focus.team IS NOT NULL
),
-- Split by kind, so each branch walks its own index instead of every time of the puzzle
subject_times AS (
    SELECT focus.id FROM focus
    UNION
    SELECT pst.id
    FROM focus
        INNER JOIN puzzle_solving_time pst ON pst.player_id = focus.player_id
            AND pst.puzzle_id = focus.puzzle_id
            AND pst.puzzling_type = focus.puzzling_type
            AND pst.team IS NULL
    WHERE focus.team IS NULL
    UNION
    SELECT pst.id
    FROM focus
        INNER JOIN puzzle_solving_time pst ON pst.puzzling_team_id = focus.puzzling_team_id
            AND pst.puzzle_id = focus.puzzle_id
            AND pst.puzzling_type = focus.puzzling_type
    WHERE focus.team IS NOT NULL
)
SELECT
    pst.id AS time_id,
    pst.player_id AS tracked_by_id,
    pst.seconds_to_solve AS time,
    pst.finished_at,
    pst.tracked_at,
    pst.first_attempt,
    pst.unboxed,
    pst.suspicious,
    pst.comment,
    pst.finished_puzzle_photo,
    competition.id AS competition_id,
    competition.shortcut AS competition_shortcut,
    competition.name AS competition_name,
    competition.slug AS competition_slug,
    cs.name AS competition_series_name,
    cs.shortcut AS competition_series_shortcut,
    cs.slug AS competition_series_slug,
    focus.puzzling_type,
    focus.puzzling_team_id::varchar AS team_id,
    puzzling_team.name AS team_name,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image END AS puzzle_image,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image_ratio END AS puzzle_image_ratio,
    puzzle.pieces_count,
    manufacturer.name AS manufacturer_name,
    focus_player.id AS player_id,
    focus_player.name AS player_name,
    focus_player.code AS player_code,
    focus_player.country AS player_country,
    focus_player.avatar AS player_avatar,
    {$privateProfileAccess->sqlIsPrivate('focus_player')} AS is_private,
    ps.skill_tier,
    focus_player.ranking_opted_out,
    focus_members.players
FROM focus
    INNER JOIN subject_times ON true
    INNER JOIN puzzle_solving_time pst ON pst.id = subject_times.id
    INNER JOIN puzzle ON puzzle.id = focus.puzzle_id
    INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
    INNER JOIN player focus_player ON focus_player.id = focus.player_id
    LEFT JOIN player_skill ps ON ps.player_id = focus_player.id
    LEFT JOIN puzzling_team ON puzzling_team.id = focus.puzzling_team_id
    LEFT JOIN competition ON competition.id = pst.competition_id
    LEFT JOIN competition_series cs ON cs.id = COALESCE(competition.series_id, pst.competition_series_id)
    LEFT JOIN focus_members ON true
WHERE true
    {$notHidden}
ORDER BY COALESCE(pst.finished_at, pst.tracked_at) DESC, pst.tracked_at DESC
SQL;

        /** @var list<array<string, mixed>> */
        return $this->database->fetchAllAssociative($query, [
            'timeId' => $timeId,
            'now' => self::getContainer()->get(ClockInterface::class)->now()->format('Y-m-d H:i:s'),
        ]);
    }
}
