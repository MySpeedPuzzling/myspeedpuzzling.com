<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    shortName: 'LookupSolvingTimes',
    operations: [
        new Post(
            uriTemplate: '/v1/me/solving-times/lookup',
            status: 200,
            openapi: new OpenApiOperation(
                tags: ['My Results & Solving Times'],
                summary: 'Check which solving times still exist',
                description: 'Read-only (POST only because up to 500 ids do not fit a URL). Send the time_ids an app saved '
                    . '(1-500 per request); every id comes back in exactly one list: '
                    . 'found - the result exists and is the token owner\'s (tracked by them, or a pair/team result they are a registered member of), '
                    . 'with its current puzzle_id (it changes when moderators merge duplicate puzzles - the time keeps its id), '
                    . 'type (solo / duo / team: the GET /me/results list it is in) and tracked_by_me (false = only its tracker may edit or delete it); '
                    . 'deleted - the result was deleted, with deleted_at (known for deletions since 2026-10-27, kept 24 months); '
                    . 'missing - unknown, not the token owner\'s, or deleted before deletions were recorded. '
                    . 'Rate limit: 60 requests per minute per token owner, shared with GET /api/v1/me/solving-times/deleted (429 with Retry-After).',
                responses: [
                    '200' => new OpenApiResponse(description: 'Every id sent, in found, deleted or missing.'),
                    '422' => new OpenApiResponse(description: 'time_ids empty, longer than 500, or not UUIDs (application/problem+json with "violations").'),
                    '429' => new OpenApiResponse(description: 'Rate limit exceeded - "Retry-After" says in how many seconds the next request is accepted.'),
                ],
            ),
            security: "is_granted('ROLE_PAT') or is_granted('ROLE_OAUTH2_RESULTS:READ')",
            output: SolvingTimesLookupResponse::class,
            processor: LookupSolvingTimesProcessor::class,
        ),
    ],
)]
final class LookupSolvingTimesInput
{
    /** @var list<string> */
    #[Assert\Count(min: 1, max: 500)]
    // The UUID text form only - any version (ids of old rows are not all RFC 4122 ones)
    #[Assert\All([new Assert\NotBlank(), new Assert\Regex(pattern: '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', message: 'This value is not a valid UUID.')])]
    public array $timeIds = [];
}
