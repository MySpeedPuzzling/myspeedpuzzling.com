<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Put;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    shortName: 'UpdateSolvingTime',
    operations: [
        new Put(
            uriTemplate: '/v1/me/solving-times/{timeId}',
            security: "is_granted('ROLE_PAT') or is_granted('ROLE_OAUTH2_SOLVING_TIMES:WRITE')",
            output: SolvingTimeResponse::class,
            processor: UpdateSolvingTimeProcessor::class,
        ),
    ],
)]
final class UpdateSolvingTimeInput
{
    #[Assert\Regex(pattern: '/^\d{1,2}:\d{2}(:\d{2})?$/', message: 'Time must be in format HH:MM:SS or MM:SS')]
    public null|string $time = null;

    public null|string $comment = null;

    public null|string $finished_at = null;

    public bool $first_attempt = false;

    public bool $unboxed = false;

    /** @var array<string> */
    public array $group_players = [];
}
