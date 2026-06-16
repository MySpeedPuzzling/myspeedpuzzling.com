<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Value\ComparisonSubject;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Session-backed "bucket" of players the user is comparing. Holds an ordered
 * list of subjects (base player + optional required co-solvers). Survives
 * navigation so the user can keep adding players from anywhere on the site.
 */
final class ComparisonBucket
{
    private const string SESSION_KEY = 'player_comparison_bucket';

    public const int MAX_SUBJECTS = 12;

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @return list<ComparisonSubject>
     */
    public function getSubjects(): array
    {
        return array_map(
            static fn (array $subject): ComparisonSubject => new ComparisonSubject(
                $subject['playerId'],
                $subject['coSolvers'],
            ),
            $this->read(),
        );
    }

    /**
     * @return list<string>
     */
    public function playerIds(): array
    {
        return array_map(static fn (array $subject): string => $subject['playerId'], $this->read());
    }

    public function count(): int
    {
        return count($this->read());
    }

    public function isEmpty(): bool
    {
        return $this->read() === [];
    }

    public function hasPlayer(string $playerId): bool
    {
        foreach ($this->read() as $subject) {
            if ($subject['playerId'] === $playerId) {
                return true;
            }
        }

        return false;
    }

    public function addPlayer(string $playerId): void
    {
        $subjects = $this->read();

        if ($this->hasPlayer($playerId) || count($subjects) >= self::MAX_SUBJECTS) {
            return;
        }

        $subjects[] = ['playerId' => $playerId, 'coSolvers' => []];

        $this->write($subjects);
    }

    public function removePlayer(string $playerId): void
    {
        $subjects = array_values(array_filter(
            $this->read(),
            static fn (array $subject): bool => $subject['playerId'] !== $playerId,
        ));

        $this->write($subjects);
    }

    public function addCoSolver(string $playerId, string $coSolverId): void
    {
        if ($playerId === $coSolverId) {
            return;
        }

        $subjects = $this->read();

        foreach ($subjects as $index => $subject) {
            if ($subject['playerId'] === $playerId && in_array($coSolverId, $subject['coSolvers'], true) === false) {
                $subjects[$index]['coSolvers'][] = $coSolverId;
            }
        }

        $this->write($subjects);
    }

    public function removeCoSolver(string $playerId, string $coSolverId): void
    {
        $subjects = $this->read();

        foreach ($subjects as $index => $subject) {
            if ($subject['playerId'] === $playerId) {
                $subjects[$index]['coSolvers'] = array_values(array_filter(
                    $subject['coSolvers'],
                    static fn (string $id): bool => $id !== $coSolverId,
                ));
            }
        }

        $this->write($subjects);
    }

    public function clear(): void
    {
        $this->write([]);
    }

    /**
     * @return list<array{playerId: string, coSolvers: list<string>}>
     */
    private function read(): array
    {
        try {
            $data = $this->requestStack->getSession()->get(self::SESSION_KEY, []);
        } catch (SessionNotFoundException) {
            return [];
        }

        if (is_array($data) === false) {
            return [];
        }

        $subjects = [];

        foreach ($data as $item) {
            if (is_array($item) === false || isset($item['playerId']) === false || is_string($item['playerId']) === false) {
                continue;
            }

            $coSolvers = [];

            if (isset($item['coSolvers']) && is_array($item['coSolvers'])) {
                foreach ($item['coSolvers'] as $coSolver) {
                    if (is_string($coSolver)) {
                        $coSolvers[] = $coSolver;
                    }
                }
            }

            $subjects[] = ['playerId' => $item['playerId'], 'coSolvers' => $coSolvers];
        }

        return $subjects;
    }

    /**
     * @param list<array{playerId: string, coSolvers: list<string>}> $subjects
     */
    private function write(array $subjects): void
    {
        try {
            $this->requestStack->getSession()->set(self::SESSION_KEY, $subjects);
        } catch (SessionNotFoundException) {
            // No session available (e.g. in CLI/tests) — nothing to persist.
        }
    }
}
