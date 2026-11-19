<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use ArrayObject;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Type;

/**
 * GET /api/v1/puzzles/{puzzleId}/leaderboard - the ranking of the website's
 * puzzle page as an API: the same read model (GetPuzzleSolvers), the same
 * grouping (one row per player / exact pair or team, its best time), the same
 * first-try and country filters and the same privacy (blocked players never,
 * fully private rows hidden, private members of a pair/team masked - all
 * relative to the token owner, PuzzleLeaderboardResponseProvider).
 */
#[ApiResource(
    shortName: 'PuzzleLeaderboard',
    operations: [
        new Get(
            uriTemplate: '/v1/puzzles/{puzzleId}/leaderboard',
            openapi: new OpenApiOperation(
                tags: ['Puzzles'],
                summary: 'Fastest results on one puzzle (the puzzle page leaderboard)',
                description: 'Returns the ranking of one puzzle exactly as the website\'s puzzle page shows it: one row per '
                    . 'player (solo) or per exact pair / team (duo, team), with its best time, fastest first. '
                    . 'first_attempt_only=true ranks only first tries; "country" keeps the rows with a player from that '
                    . 'country (for a pair or team: any of its members). Times that still need verification are never '
                    . 'ranked. Players who blocked the token owner (or whom the owner blocked) are left out, a private '
                    . 'player\'s solo row is left out unless it is the token owner\'s own, and a private member of a pair '
                    . 'or team is masked (id, name, code and country null, is_private true). "rank" is the position in '
                    . 'the filtered list. An unknown or malformed puzzle id is a 404, and so is a secret competition '
                    . 'puzzle until it is revealed. Pagination is capped at 100 items per page.',
                responses: [
                    '200' => new OpenApiResponse(
                        description: 'One page of the leaderboard.',
                        content: new ArrayObject([
                            'application/json' => new MediaType(example: [
                                'puzzle_id' => '018d0002-0000-0000-0000-000000000001',
                                'type' => 'solo',
                                'country' => null,
                                'first_attempt_only' => false,
                                'count' => 2,
                                'total' => 37,
                                'page' => 1,
                                'limit' => 2,
                                'has_more' => true,
                                'entries' => [
                                    [
                                        'rank' => 1,
                                        'time_id' => '018d0004-0000-0000-0000-000000000032',
                                        'time_seconds' => 1200,
                                        'finished_at' => '2026-11-08T00:00:00+00:00',
                                        'first_attempt' => true,
                                        'team_id' => null,
                                        'team_name' => null,
                                        'players' => [
                                            ['id' => '018d0000-0000-0000-0000-000000000003', 'name' => 'Admin User', 'code' => 'ADMIN', 'country' => 'cz', 'is_private' => false],
                                        ],
                                    ],
                                    [
                                        'rank' => 2,
                                        'time_id' => '018d0004-0000-0000-0000-000000000036',
                                        'time_seconds' => 1750,
                                        'finished_at' => '2026-11-16T00:00:00+00:00',
                                        'first_attempt' => false,
                                        'team_id' => null,
                                        'team_name' => null,
                                        'players' => [
                                            ['id' => '018d0000-0000-0000-0000-000000000001', 'name' => 'John Doe', 'code' => 'JOHN', 'country' => 'cz', 'is_private' => false],
                                        ],
                                    ],
                                ],
                            ]),
                        ]),
                    ),
                    '401' => new OpenApiResponse(description: 'Missing, invalid or expired token.'),
                    '404' => new OpenApiResponse(description: 'Unknown or malformed puzzle id, or a secret competition puzzle that has not been revealed yet.'),
                    '422' => new OpenApiResponse(description: 'Invalid query parameters (application/problem+json with "violations").'),
                ],
            ),
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            provider: PuzzleLeaderboardResponseProvider::class,
            parameters: [
                'type' => new QueryParameter(
                    key: 'type',
                    schema: ['type' => 'string', 'enum' => self::TYPES, 'default' => 'solo'],
                    description: 'Which leaderboard: solo (default), duo (pairs) or team (groups of three and more).',
                    constraints: [new Choice(choices: self::TYPES)],
                ),
                'country' => new QueryParameter(
                    key: 'country',
                    schema: ['type' => 'string', 'pattern' => '^[A-Za-z]{2}$', 'example' => 'cz'],
                    description: 'ISO 3166-1 alpha-2 country code (case-insensitive), e.g. "cz" or "US": only rows with a player from that country - for a pair or team, any of its members. Surrounding whitespace is ignored; an empty value is the same as omitting it.',
                    constraints: [new Choice(callback: [self::class, 'countryCodes'], message: 'country must be an ISO 3166-1 alpha-2 country code.')],
                    castToNativeType: true,
                    castFn: [QueryParameterCaster::class, 'lowercase'],
                ),
                'first_attempt_only' => new QueryParameter(
                    key: 'first_attempt_only',
                    schema: ['type' => 'boolean', 'default' => false],
                    description: 'true: rank only first tries (each player\'s / pair\'s / team\'s first attempt at this puzzle). Accepts true/false/1/0.',
                    constraints: [new Type(type: 'bool', message: 'first_attempt_only must be true, false, 1 or 0.')],
                    castToNativeType: true,
                ),
                'page' => new QueryParameter(
                    key: 'page',
                    schema: ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                    description: 'Page number, 1-based.',
                    constraints: [new Type(type: 'integer'), new Range(min: 1)],
                    castToNativeType: true,
                ),
                'limit' => new QueryParameter(
                    key: 'limit',
                    schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                    description: 'Items per page, at most 100.',
                    constraints: [new Type(type: 'integer'), new Range(min: 1, max: 100)],
                    castToNativeType: true,
                ),
            ],
        ),
    ],
)]
final class PuzzleLeaderboardResponse
{
    /** @var list<string> */
    public const array TYPES = ['solo', 'duo', 'team'];

    /**
     * @param list<PuzzleLeaderboardEntryResponse> $entries
     */
    public function __construct(
        public string $puzzleId,
        public string $type,
        /** The country filter applied, lowercase - null when none */
        public null|string $country,
        public bool $firstAttemptOnly,
        /** Number of rows on this page */
        public int $count,
        /** Number of rows of the filtered leaderboard altogether */
        public int $total,
        public int $page,
        public int $limit,
        public bool $hasMore,
        /** @var list<PuzzleLeaderboardEntryResponse> */
        public array $entries,
    ) {
    }

    /**
     * The accepted values of "country": the case names of CountryCode (lowercase ISO 3166-1 alpha-2)
     *
     * @return list<string>
     */
    public static function countryCodes(): array
    {
        return array_map(static fn (CountryCode $code): string => $code->name, CountryCode::cases());
    }
}
