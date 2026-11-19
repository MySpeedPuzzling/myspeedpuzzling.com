<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use SpeedPuzzling\Web\Results\PuzzleSolver;
use SpeedPuzzling\Web\Value\Puzzler;

/**
 * A person on a leaderboard row. A private player (hidden from the token owner)
 * is masked - every identifying field null - exactly like the website's
 * "secret puzzler"; a guest of a pair / team has a name but no id or code.
 */
final readonly class PuzzleLeaderboardPlayerResponse
{
    public function __construct(
        public null|string $id,
        public null|string $name,
        public null|string $code,
        /** ISO 3166-1 alpha-2, lowercase */
        public null|string $country,
        public bool $isPrivate,
    ) {
    }

    public static function fromSolver(PuzzleSolver $solver, null|string $viewerId): self
    {
        if ($solver->isPrivate && $solver->playerId !== $viewerId) {
            return self::masked();
        }

        return new self(
            id: $solver->playerId,
            name: $solver->playerName,
            code: $solver->playerCode,
            country: $solver->playerCountry?->name,
            isPrivate: false,
        );
    }

    public static function fromPuzzler(Puzzler $puzzler, null|string $viewerId): self
    {
        if ($puzzler->isPrivate && ($puzzler->playerId === null || $puzzler->playerId !== $viewerId)) {
            return self::masked();
        }

        return new self(
            id: $puzzler->playerId,
            name: $puzzler->playerName,
            code: $puzzler->playerCode !== null ? strtoupper($puzzler->playerCode) : null,
            country: $puzzler->playerCountry?->name,
            isPrivate: false,
        );
    }

    private static function masked(): self
    {
        return new self(id: null, name: null, code: null, country: null, isPrivate: true);
    }
}
