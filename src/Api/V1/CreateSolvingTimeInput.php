<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    shortName: 'CreateSolvingTime',
    operations: [
        new Post(
            uriTemplate: '/v1/me/solving-times',
            openapi: new OpenApiOperation(tags: ['My Results & Solving Times']),
            security: "is_granted('ROLE_PAT') or is_granted('ROLE_OAUTH2_SOLVING_TIMES:WRITE')",
            output: SolvingTimeResponse::class,
            processor: CreateSolvingTimeProcessor::class,
        ),
    ],
)]
final class CreateSolvingTimeInput
{
    #[Assert\NotBlank]
    public string $puzzle_id = '';

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^\d{1,2}:\d{2}(:\d{2})?$/', message: 'Time must be in format HH:MM:SS or MM:SS')]
    public string $time = '';

    public null|string $comment = null;

    public null|string $finished_at = null;

    public bool $first_attempt = false;

    public bool $unboxed = false;

    /** @var array<string> */
    public array $group_players = [];
}
