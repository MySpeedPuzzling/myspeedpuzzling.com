<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ParameterNotFound;
use ApiPlatform\State\ProviderInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetPuzzleSolvers;
use SpeedPuzzling\Web\Results\PuzzleSolver;
use SpeedPuzzling\Web\Results\PuzzleSolversGroup;
use SpeedPuzzling\Web\Services\Api\ApiTokenOwner;
use SpeedPuzzling\Web\Services\PuzzlesSorter;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\Puzzler;

/**
 * GET /api/v1/puzzles/{puzzleId}/leaderboard - the puzzle page's leaderboard
 * (PuzzleTimes component) without the page: the same query (GetPuzzleSolvers -
 * blocked players and suspicious times already left out in SQL, private
 * players flagged relative to the token owner), the same PuzzlesSorter steps
 * (best attempt per player / exact pair-team, first-try filter, private rows
 * out unless the owner's own, country filter), paginated in memory like the
 * website's "Show more". No new SQL.
 *
 * @implements ProviderInterface<PuzzleLeaderboardResponse>
 */
final readonly class PuzzleLeaderboardResponseProvider implements ProviderInterface
{
    public function __construct(
        private GetPuzzleOverview $getPuzzleOverview,
        private GetPuzzleSolvers $getPuzzleSolvers,
        private PuzzlesSorter $puzzlesSorter,
        private ApiTokenOwner $tokenOwner,
        private ClockInterface $clock,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PuzzleLeaderboardResponse
    {
        /** @var string $puzzleId */
        $puzzleId = $uriVariables['puzzleId'];

        if (Uuid::isValid($puzzleId) === false) {
            throw new PuzzleNotFound();
        }

        // 404 for an unknown puzzle and - like GET /v1/puzzles/{id} - for a secret competition puzzle
        $overview = $this->getPuzzleOverview->byId($puzzleId);

        if ($overview->hideUntil !== null && $overview->hideUntil > $this->clock->now()) {
            throw new PuzzleNotFound();
        }

        $type = $this->stringValue($operation, 'type') ?? 'solo';
        $country = CountryCode::fromCode($this->stringValue($operation, 'country'));
        $firstAttemptOnly = $this->boolValue($operation, 'first_attempt_only');
        $page = $this->intValue($operation, 'page', 1);
        $limit = $this->intValue($operation, 'limit', 20);

        $viewerId = $this->tokenOwner->profile()?->playerId;

        $attempts = match ($type) {
            'duo' => $this->getPuzzleSolvers->duoByPuzzleId($puzzleId),
            'team' => $this->getPuzzleSolvers->teamByPuzzleId($puzzleId),
            default => $this->getPuzzleSolvers->soloByPuzzleId($puzzleId),
        };

        // The steps of PuzzleTimes::leaderboardRows() + populate(), in the same order
        $attempts = $firstAttemptOnly
            ? $this->puzzlesSorter->sortByFirstTry($attempts)
            : $this->puzzlesSorter->sortByFastest($attempts);

        $rows = $this->puzzlesSorter->filterGroupedByAttempt(
            $this->puzzlesSorter->groupPlayers($attempts),
            onlyFirstTries: $firstAttemptOnly,
            onlyUnboxed: false,
        );
        $rows = $this->puzzlesSorter->filterOutPrivateProfiles($rows, $viewerId);

        if ($country !== null) {
            $rows = $this->puzzlesSorter->filterByCountry($rows, $country);
        }

        $rows = array_values($rows);
        $total = count($rows);
        $offset = ($page - 1) * $limit;

        $entries = [];

        foreach (array_slice($rows, $offset, $limit) as $index => $grouped) {
            $entries[] = $this->entry($offset + $index + 1, $grouped[0], $viewerId);
        }

        return new PuzzleLeaderboardResponse(
            puzzleId: $puzzleId,
            type: $type,
            country: $country?->name,
            firstAttemptOnly: $firstAttemptOnly,
            count: count($entries),
            total: $total,
            page: $page,
            limit: $limit,
            hasMore: $offset + count($entries) < $total,
            entries: $entries,
        );
    }

    private function entry(int $rank, PuzzleSolver|PuzzleSolversGroup $best, null|string $viewerId): PuzzleLeaderboardEntryResponse
    {
        if ($best instanceof PuzzleSolver) {
            $players = [PuzzleLeaderboardPlayerResponse::fromSolver($best, $viewerId)];
        } else {
            $players = array_values(array_map(
                static fn (Puzzler $puzzler): PuzzleLeaderboardPlayerResponse => PuzzleLeaderboardPlayerResponse::fromPuzzler($puzzler, $viewerId),
                $best->players,
            ));
        }

        return new PuzzleLeaderboardEntryResponse(
            rank: $rank,
            timeId: $best->timeId,
            // A leaderboard row is always timed - the query selects only rows with seconds_to_solve
            timeSeconds: $best->time ?? 0,
            finishedAt: $best->finishedAt?->format('c'),
            firstAttempt: $best->firstAttempt,
            teamId: $best instanceof PuzzleSolversGroup ? $best->teamId : null,
            teamName: $best instanceof PuzzleSolversGroup ? $best->teamName : null,
            players: $players,
        );
    }

    private function stringValue(Operation $operation, string $key): null|string
    {
        $value = $operation->getParameters()?->get($key)?->getValue();

        if ($value instanceof ParameterNotFound || $value === null || $value === '') {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    private function intValue(Operation $operation, string $key, int $default): int
    {
        $value = $operation->getParameters()?->get($key)?->getValue();

        return is_int($value) ? $value : $default;
    }

    private function boolValue(Operation $operation, string $key): bool
    {
        return $operation->getParameters()?->get($key)?->getValue() === true;
    }
}
