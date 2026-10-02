<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    shortName: 'CreateSolvingTime',
    operations: [
        new Post(
            uriTemplate: '/v1/me/solving-times',
            openapi: new OpenApiOperation(
                tags: ['My Results & Solving Times'],
                summary: 'Add a solving time for the token owner',
                description: 'time is HH:MM:SS or MM:SS; the response carries it parsed as time_seconds. '
                    . 'group_players (player codes prefixed with #, or plain names for guests) makes the time a duo/team one. '
                    . 'The response also carries prediction - the time prediction that applied *before* this solve, '
                    . 'what the website shows on the added-time recap: the new time is excluded, so personal_solve_count is the count before it. '
                    . 'Puzzle Insights are members-only and self-only, exactly as on the website: prediction is null for a group time, '
                    . 'a non-member, an owner who opted out of time predictions, and for an OAuth2 token without results:read '
                    . '(the write scope alone does not read insights; a personal access token always can). '
                    . 'When present, every field inside is null and is_personalized false if there is nothing to predict from. '
                    . 'Send an Idempotency-Key header to retry safely: a request repeating a key already used by the token owner '
                    . 'creates nothing and answers with the result the first request saved (same status, same body) - as long as it is the same '
                    . 'result (puzzle_id, time and finish day); a key reused for a different result is refused with 422 '
                    . 'application/problem+json, type "/errors/idempotency_key_reused", and nothing is saved. '
                    . 'Without the key, an identical request sent again within 10 seconds is answered the same way.',
                parameters: [
                    new Parameter(
                        name: 'Idempotency-Key',
                        in: 'header',
                        description: 'Optional. Any string unique per new result (e.g. a UUID the app generates once per result and reuses on every retry). '
                            . 'Keys are scoped to the token owner and never expire.',
                        required: false,
                        schema: ['type' => 'string'],
                    ),
                ],
                responses: [
                    '422' => new OpenApiResponse(description: 'Invalid input (application/problem+json with "violations"), a first attempt already recorded '
                        . '(firstAttempt=true), or an Idempotency-Key already used for a different result (type "/errors/idempotency_key_reused").'),
                ],
            ),
            security: "is_granted('ROLE_PAT') or is_granted('ROLE_OAUTH2_SOLVING-TIMES:WRITE')",
            output: SolvingTimeResponse::class,
            processor: CreateSolvingTimeProcessor::class,
        ),
    ],
)]
final class CreateSolvingTimeInput
{
    #[Assert\NotBlank]
    public string $puzzleId = '';

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^\d{1,2}:\d{2}(:\d{2})?$/', message: 'Time must be in format HH:MM:SS or MM:SS')]
    public string $time = '';

    public null|string $comment = null;

    public null|string $finishedAt = null;

    public bool $firstAttempt = false;

    public bool $unboxed = false;

    public null|string $roundId = null;

    /** @var array<string> */
    public array $groupPlayers = [];
}
