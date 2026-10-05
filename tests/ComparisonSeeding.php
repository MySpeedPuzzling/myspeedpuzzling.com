<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\PuzzleSearchKeys;

/**
 * Isolated players, puzzles, pairs/teams and times for the comparison read side (docs/features/player-comparison.md) -
 * the fixtures share their puzzles with dozens of tests, a comparison needs exact control over who solved what when.
 * Plain SQL on purpose: persisting entities would dispatch PuzzleSolved (statistics, notifications, insights) for every
 * time. DAMA rolls everything back with the test.
 */
trait ComparisonSeeding
{
    private int $seededCodes = 0;

    protected function seedPlayer(
        string $name,
        bool $private = false,
        bool $rankingOptedOut = false,
        null|string $code = null,
        null|string $country = null,
        null|string $avatar = null,
        // Needed to sign the player in (TestingViewer)
        null|string $userId = null,
    ): string {
        $id = Uuid::uuid7()->toString();
        $code ??= 'cmp' . (++$this->seededCodes) . substr(str_replace('-', '', $id), -6);

        $this->comparisonDatabase()->executeStatement(
            <<<SQL
INSERT INTO player (id, code, name, country, avatar, user_id, registered_at, is_private, ranking_opted_out)
VALUES (:id, :code, :name, :country, :avatar, :userId, NOW() - INTERVAL '2 years', :private, :rankingOptedOut)
SQL,
            [
                'id' => $id,
                'code' => $code,
                'name' => $name,
                'country' => $country,
                'avatar' => $avatar,
                'userId' => $userId,
                'private' => $private,
                'rankingOptedOut' => $rankingOptedOut,
            ],
            ['private' => ParameterType::BOOLEAN, 'rankingOptedOut' => ParameterType::BOOLEAN],
        );

        return $id;
    }

    protected function seedManufacturer(string $name): string
    {
        $id = Uuid::uuid7()->toString();

        $this->comparisonDatabase()->executeStatement(
            'INSERT INTO manufacturer (id, name, approved) VALUES (:id, :name, true)',
            ['id' => $id, 'name' => $name],
        );

        return $id;
    }

    protected function seedPuzzle(
        int $pieces,
        string $name = 'Comparison puzzle',
        null|string $manufacturerId = null,
        null|DateTimeImmutable $hideUntil = null,
        null|DateTimeImmutable $hideImageUntil = null,
        null|string $image = null,
        null|float $imageRatio = null,
    ): string {
        $id = Uuid::uuid7()->toString();
        // As the entity writes them (Puzzle::changeNames()): the list and the search keys
        $alternativeNames = new PuzzleNames();

        $this->comparisonDatabase()->executeStatement(
            <<<SQL
INSERT INTO puzzle (id, manufacturer_id, pieces_count, name, alternative_names, search_names, search_codes, approved, is_available, image, image_ratio, hide_until, hide_image_until)
VALUES (:id, :manufacturerId, :pieces, :name, :alternativeNames, :searchNames, :searchCodes, true, true, :image, :imageRatio, :hideUntil, :hideImageUntil)
SQL,
            [
                'id' => $id,
                'manufacturerId' => $manufacturerId,
                'pieces' => $pieces,
                'name' => $name,
                'alternativeNames' => json_encode($alternativeNames->toArray(), JSON_THROW_ON_ERROR),
                'searchNames' => PuzzleSearchKeys::names($name, $alternativeNames),
                'searchCodes' => PuzzleSearchKeys::codes(null, null),
                'image' => $image,
                'imageRatio' => $imageRatio,
                'hideUntil' => $hideUntil?->format('Y-m-d H:i:s'),
                'hideImageUntil' => $hideImageUntil?->format('Y-m-d H:i:s'),
            ],
            ['pieces' => ParameterType::INTEGER],
        );

        return $id;
    }

    protected function seedDifficulty(string $puzzleId, null|int $tier): void
    {
        $this->comparisonDatabase()->executeStatement(
            <<<SQL
INSERT INTO puzzle_difficulty (puzzle_id, difficulty_tier, difficulty_score, confidence, sample_size, computed_at)
VALUES (:puzzleId, :tier, 1.0, 'high', 10, NOW())
SQL,
            ['puzzleId' => $puzzleId, 'tier' => $tier],
        );
    }

    /**
     * A pair/team of registered players (ids) and guests (names), in that order.
     *
     * @param list<string> $playerIds
     * @param list<string> $guestNames
     */
    protected function seedTeam(array $playerIds, array $guestNames = [], null|string $name = null): string
    {
        $id = Uuid::uuid7()->toString();
        $database = $this->comparisonDatabase();

        $database->executeStatement(
            <<<SQL
INSERT INTO puzzling_team (id, composition_key, size, created_at, name)
VALUES (:id, :compositionKey, :size, NOW(), :name)
SQL,
            [
                'id' => $id,
                'compositionKey' => sha1($id),
                'size' => count($playerIds) + count($guestNames),
                'name' => $name,
            ],
            ['size' => ParameterType::INTEGER],
        );

        $position = 0;

        foreach ($playerIds as $playerId) {
            $database->executeStatement(
                'INSERT INTO puzzling_team_member (id, team_id, member_key, player_id, guest_name, position) VALUES (:id, :teamId, :key, :playerId, NULL, :position)',
                ['id' => Uuid::uuid7()->toString(), 'teamId' => $id, 'key' => $playerId, 'playerId' => $playerId, 'position' => $position++],
                ['position' => ParameterType::INTEGER],
            );
        }

        foreach ($guestNames as $guestName) {
            $database->executeStatement(
                'INSERT INTO puzzling_team_member (id, team_id, member_key, player_id, guest_name, position) VALUES (:id, :teamId, :key, NULL, :guestName, :position)',
                ['id' => Uuid::uuid7()->toString(), 'teamId' => $id, 'key' => 'g:' . mb_strtolower($guestName), 'guestName' => $guestName, 'position' => $position++],
                ['position' => ParameterType::INTEGER],
            );
        }

        return $id;
    }

    /**
     * A solo time of $playerId, or - with $teamId - a time of that pair/team tracked by $playerId (the `team` snapshot
     * lists the team's members).
     */
    protected function seedTime(
        string $playerId,
        string $puzzleId,
        null|int $seconds,
        DateTimeImmutable $day,
        bool $firstAttempt = false,
        bool $suspicious = false,
        null|string $teamId = null,
        null|DateTimeImmutable $trackedAt = null,
        bool $finishedAtIsNull = false,
    ): string {
        $id = Uuid::uuid7()->toString();
        $database = $this->comparisonDatabase();
        $team = null;
        $type = 'solo';
        $puzzlersCount = 1;

        if ($teamId !== null) {
            /** @var list<array{player_id: null|string, guest_name: null|string}> $members */
            $members = $database->fetchAllAssociative(
                'SELECT player_id, guest_name FROM puzzling_team_member WHERE team_id = :teamId ORDER BY position',
                ['teamId' => $teamId],
            );
            $team = json_encode([
                'team_id' => null,
                'puzzlers' => array_map(
                    static fn(array $member): array => ['player_id' => $member['player_id'], 'player_name' => $member['guest_name']],
                    $members,
                ),
            ], JSON_THROW_ON_ERROR);
            $puzzlersCount = count($members);
            $type = $puzzlersCount === 2 ? 'duo' : 'team';
        }

        $database->executeStatement(
            <<<SQL
INSERT INTO puzzle_solving_time (id, player_id, puzzle_id, seconds_to_solve, tracked_at, finished_at, verified, first_attempt, suspicious, team, puzzling_team_id, puzzling_type, puzzlers_count)
VALUES (:id, :playerId, :puzzleId, :seconds, :trackedAt, :finishedAt, true, :firstAttempt, :suspicious, :team, :teamId, :type, :puzzlersCount)
SQL,
            [
                'id' => $id,
                'playerId' => $playerId,
                'puzzleId' => $puzzleId,
                'seconds' => $seconds,
                'trackedAt' => ($trackedAt ?? $day)->format('Y-m-d H:i:s'),
                'finishedAt' => $finishedAtIsNull ? null : $day->format('Y-m-d H:i:s'),
                'firstAttempt' => $firstAttempt,
                'suspicious' => $suspicious,
                'team' => $team,
                'teamId' => $teamId,
                'type' => $type,
                'puzzlersCount' => $puzzlersCount,
            ],
            [
                'seconds' => ParameterType::INTEGER,
                'firstAttempt' => ParameterType::BOOLEAN,
                'suspicious' => ParameterType::BOOLEAN,
                'puzzlersCount' => ParameterType::INTEGER,
            ],
        );

        return $id;
    }

    protected function seedBlock(string $blockerId, string $blockedId): void
    {
        $this->comparisonDatabase()->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }

    protected function seedAllowList(string $ownerId, string $viewerId): void
    {
        $this->comparisonDatabase()->executeStatement(
            'INSERT INTO private_profile_viewer (id, owner_id, viewer_id, added_at) VALUES (:id, :owner, :viewer, NOW())',
            ['id' => Uuid::uuid7()->toString(), 'owner' => $ownerId, 'viewer' => $viewerId],
        );
    }

    protected function seedSkill(string $playerId, float $percentile, int $tier, int $pieces = 500): void
    {
        $this->comparisonDatabase()->executeStatement(
            <<<SQL
INSERT INTO player_skill (id, player_id, pieces_count, skill_score, skill_tier, skill_percentile, confidence, qualifying_puzzles_count, computed_at)
VALUES (:id, :playerId, :pieces, 1.0, :tier, :percentile, 'high', 10, NOW())
SQL,
            ['id' => Uuid::uuid7()->toString(), 'playerId' => $playerId, 'pieces' => $pieces, 'tier' => $tier, 'percentile' => $percentile],
            ['pieces' => ParameterType::INTEGER, 'tier' => ParameterType::INTEGER],
        );
    }

    protected function seedBaseline(string $playerId, int $pieces, int $seconds, int $qualifyingSolves = 10): void
    {
        $this->comparisonDatabase()->executeStatement(
            <<<SQL
INSERT INTO player_baseline (id, player_id, pieces_count, baseline_seconds, qualifying_solves_count, computed_at)
VALUES (:id, :playerId, :pieces, :seconds, :solves, NOW())
SQL,
            ['id' => Uuid::uuid7()->toString(), 'playerId' => $playerId, 'pieces' => $pieces, 'seconds' => $seconds, 'solves' => $qualifyingSolves],
            ['pieces' => ParameterType::INTEGER, 'seconds' => ParameterType::INTEGER, 'solves' => ParameterType::INTEGER],
        );
    }

    private function comparisonDatabase(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
