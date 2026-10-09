<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    shortName: 'UpdateSolvingTime',
    operations: [
        new Put(
            uriTemplate: '/v1/me/solving-times/{timeId}',
            openapi: new OpenApiOperation(
                tags: ['My Results & Solving Times'],
                description: 'Edits a solving time the token owner may change (theirs, or a pair/team result they are in). '
                    . 'The event link: without competition_id and series_id (or with both null) the time keeps the link it has, exactly - '
                    . 'a link to a one-time event or an edition stays, a series stays a series and MySpeedPuzzling finds its edition again for the edited time. '
                    . 'competition_id (a one-time event or an edition, linked explicitly) or series_id (a series - MySpeedPuzzling finds the edition, '
                    . 'else the time is a result of the series without one) changes it; sent together, series_id must be the series of that competition. '
                    . 'The time\'s current competition or series is accepted even when it is no longer publicly visible. '
                    . 'An event link cannot be removed through the API. The round always follows the competition. '
                    . 'The response carries the link as saved: round_id, competition_id and series_id (the series picked, else the series of the linked edition). '
                    . 'An unknown, malformed or not publicly visible competition_id / series_id is a 404, a series_id that disagrees with competition_id a 422 '
                    . '(application/problem+json, a violation on series_id) - nothing is saved.',
            ),
            security: "is_granted('ROLE_PAT') or is_granted('ROLE_OAUTH2_SOLVING-TIMES:WRITE')",
            output: SolvingTimeResponse::class,
            // No state provider exists for this DTO resource - without read: false,
            // ReadProvider resolves null state and 404s before the processor runs.
            read: false,
            processor: UpdateSolvingTimeProcessor::class,
        ),
    ],
)]
final class UpdateSolvingTimeInput
{
    #[Assert\Regex(pattern: '/^\d{1,2}:\d{2}(:\d{2})?$/', message: 'Time must be in format HH:MM:SS or MM:SS')]
    public null|string $time = null;

    public null|string $comment = null;

    public null|string $finishedAt = null;

    public bool $firstAttempt = false;

    public bool $unboxed = false;

    /** @var array<string> */
    public array $groupPlayers = [];

    // Both null keeps the time's event link exactly; either one changes it, like on create
    // (docs/features/events-page/high-frequency-series.md "API v1")
    public null|string $competitionId = null;

    public null|string $seriesId = null;
}
