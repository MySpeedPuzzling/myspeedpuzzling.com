<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter as OpenApiParameter;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\PuzzleSearchCriteria;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Type;

/**
 * GET /api/v1/puzzles - the puzzle catalog: search, filters, barcode lookup.
 *
 * Every query parameter is declared once, here: the declaration validates the
 * input (invalid => 422 application/problem+json with violations) and renders
 * the Swagger documentation. Cross-parameter rules (ean exclusivity,
 * pieces_min <= pieces_max) and the members-only values (sort=easiest|hardest,
 * difficulty=) are enforced in PuzzleSearchResponseProvider.
 */
#[ApiResource(
    shortName: 'PuzzleList',
    operations: [
        new Get(
            uriTemplate: '/v1/puzzles',
            openapi: new OpenApiOperation(
                tags: ['Puzzles'],
                summary: 'Search the puzzle catalog or look a puzzle up by barcode',
                description: 'Returns puzzle cards - the same catalog as the website\'s puzzle search. '
                    . 'Without any filter the whole catalog is listed (most solved first); "query" searches names, '
                    . 'alternative names, identification numbers and barcodes accent-insensitively; "ean" is an exact '
                    . 'barcode lookup (leading/trailing zeros tolerated) and cannot be combined with the other filters. '
                    . 'Secret competition puzzles are never returned and an embargoed image is null until its release. '
                    . 'Every card carries three insight objects that are always present and null when the token is not '
                    . 'entitled to them: "difficulty" (token owner must be a member), "prediction" (member who has not '
                    . 'opted out of time predictions, with a PAT or results:read) and "solves" (the token owner\'s own '
                    . 'history, PAT or results:read). A client_credentials token has no player behind it, so it gets null '
                    . 'for all three. sort=easiest|hardest and the difficulty filter are members-only (403 otherwise). '
                    . 'Pagination is capped at 100 items per page and 500 pages; the endpoint is rate-limited to 60 '
                    . 'requests per minute per token owner (429 with Retry-After).',
                responses: [
                    '401' => new OpenApiResponse(description: 'Missing, invalid or expired token.'),
                    '403' => new OpenApiResponse(description: 'sort=easiest|hardest or the difficulty filter without an active membership.'),
                    '422' => new OpenApiResponse(description: 'Invalid or contradicting query parameters (application/problem+json with "violations").'),
                    '429' => new OpenApiResponse(description: 'Rate limit exceeded - 60 requests per minute per token owner. "Retry-After" says in how many seconds the next request is accepted.'),
                ],
            ),
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            provider: PuzzleSearchResponseProvider::class,
            parameters: [
                'query' => new QueryParameter(
                    key: 'query',
                    schema: ['type' => 'string', 'minLength' => 2, 'maxLength' => 100],
                    description: 'Free-text search (2-100 characters): puzzle name and alternative name (accent-insensitive), identification number, barcode substring - the same matching as the website search box. Surrounding whitespace is ignored; an empty value is the same as omitting the parameter.',
                    constraints: [new Length(min: 2, max: 100)],
                    castToNativeType: true,
                    castFn: [QueryParameterCaster::class, 'trim'],
                ),
                'ean' => new QueryParameter(
                    key: 'ean',
                    schema: ['type' => 'string', 'pattern' => '^\d{8,14}$'],
                    description: 'Exact barcode (EAN/UPC, 8-14 digits) lookup; leading and trailing zeros are tolerated. Mutually exclusive with query, manufacturer, pieces_min, pieces_max, sort and difficulty (422 when combined).',
                    constraints: [new Regex(pattern: '/^\d{8,14}$/', message: 'ean must be 8 to 14 digits.')],
                ),
                'manufacturer' => new QueryParameter(
                    key: 'manufacturer',
                    schema: ['type' => 'string', 'format' => 'uuid'],
                    description: 'Brand (manufacturer) id. An unknown id yields an empty result, not 404.',
                    constraints: [new Regex(pattern: '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', message: 'manufacturer must be a UUID.')],
                ),
                'pieces_min' => new QueryParameter(
                    key: 'pieces_min',
                    schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 50000],
                    description: 'Lowest piece count to include (inclusive). Use pieces_min = pieces_max for an exact count.',
                    constraints: [new Type(type: 'integer'), new Range(min: 1, max: 50000)],
                    castToNativeType: true,
                ),
                'pieces_max' => new QueryParameter(
                    key: 'pieces_max',
                    schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 50000],
                    description: 'Highest piece count to include (inclusive); must not be below pieces_min (422 otherwise).',
                    constraints: [new Type(type: 'integer'), new Range(min: 1, max: 50000)],
                    castToNativeType: true,
                ),
                'sort' => new QueryParameter(
                    key: 'sort',
                    schema: ['type' => 'string', 'enum' => PuzzleSearchCriteria::VALID_SORTS, 'default' => 'most-solved'],
                    description: 'Sort order. easiest and hardest (by difficulty score) are members-only: 403 for a non-member or a client_credentials token.',
                    constraints: [new Choice(choices: PuzzleSearchCriteria::VALID_SORTS)],
                ),
                'difficulty' => new QueryParameter(
                    key: 'difficulty',
                    schema: ['type' => 'array', 'items' => ['type' => 'string', 'enum' => DifficultyTier::API_VALUES]],
                    openApi: new OpenApiParameter(name: 'difficulty', in: 'query', style: 'form', explode: false),
                    description: 'Comma-separated difficulty tiers to include, e.g. "hard,very_hard" (very_easy, easy, average, challenging, hard, very_hard). Members-only: 403 for a non-member or a client_credentials token.',
                    constraints: [new All(constraints: [new Choice(choices: DifficultyTier::API_VALUES)])],
                    castToNativeType: true,
                    castFn: [QueryParameterCaster::class, 'commaSeparatedList'],
                ),
                'page' => new QueryParameter(
                    key: 'page',
                    schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 1],
                    description: 'Page number, 1-based, at most 500.',
                    constraints: [new Type(type: 'integer'), new Range(min: 1, max: 500)],
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
final class PuzzleListResponse
{
    /**
     * @param list<PuzzleResponse> $puzzles
     */
    public function __construct(
        /** Number of puzzles on this page */
        public int $count,
        /** Number of puzzles matching the filters altogether */
        public int $total,
        public int $page,
        public int $limit,
        public bool $hasMore,
        /** @var list<PuzzleResponse> */
        public array $puzzles,
    ) {
    }
}
