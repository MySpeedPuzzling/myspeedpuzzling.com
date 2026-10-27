<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;

#[ApiResource(
    shortName: 'DeletedSolvingTimes',
    operations: [
        new Get(
            uriTemplate: '/v1/me/solving-times/deleted',
            openapi: new OpenApiOperation(
                tags: ['My Results & Solving Times'],
                summary: 'Solving times of the token owner deleted since a moment',
                description: 'Only the id and the moment of deletion, oldest first, up to 1000 per page - for an app keeping a copy of its user\'s results. '
                    . 'Lists every result the token owner was in (tracked, or a registered member of the pair/team) deleted on the website, '
                    . 'through the API or as a duplicate saved twice. A result restored afterwards (Undo of an automatic duplicate removal) leaves the list again. '
                    . 'Deletions are recorded since 2026-10-27 and kept 24 months. next_cursor is null on the last page; '
                    . 'otherwise call again with cursor=next_cursor (keep since as it was or leave it out). '
                    . 'Rate limit: 60 requests per minute per token owner, shared with POST /api/v1/me/solving-times/lookup (429 with Retry-After).',
                parameters: [
                    new Parameter(
                        name: 'since',
                        in: 'query',
                        description: 'Optional ISO 8601 moment (e.g. 2026-10-27T10:00:00Z): only deletions at or after it. Without it, every recorded deletion.',
                        required: false,
                        schema: ['type' => 'string', 'format' => 'date-time'],
                    ),
                    new Parameter(
                        name: 'cursor',
                        in: 'query',
                        description: 'Optional: the next_cursor of the previous page.',
                        required: false,
                        schema: ['type' => 'string'],
                    ),
                ],
                responses: [
                    '422' => new OpenApiResponse(description: 'since is not a date, or cursor is not one this endpoint gave.'),
                    '429' => new OpenApiResponse(description: 'Rate limit exceeded - "Retry-After" says in how many seconds the next request is accepted.'),
                ],
            ),
            security: "is_granted('ROLE_PAT') or is_granted('ROLE_OAUTH2_RESULTS:READ')",
            provider: DeletedSolvingTimesResponseProvider::class,
        ),
    ],
)]
final class DeletedSolvingTimesResponse
{
    /**
     * @param list<DeletedSolvingTimeResponse> $deleted
     */
    public function __construct(
        public int $count,
        public array $deleted,
        public null|string $nextCursor,
    ) {
    }
}
