<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;

#[ApiResource(
    shortName: 'DeleteSolvingTime',
    operations: [
        new Delete(
            uriTemplate: '/v1/me/solving-times/{timeId}',
            openapi: new OpenApiOperation(
                tags: ['My Results & Solving Times'],
                summary: 'Delete a solving time the token owner tracked',
                description: 'The same as deleting the result on the website: only the player who tracked it may delete it - '
                    . 'a registered member of somebody else\'s pair/team result gets 403. '
                    . 'Every registered member of the deleted result finds it afterwards in GET /api/v1/me/solving-times/deleted '
                    . 'and in the lookup\'s "deleted". A 404 means the id is unknown or the result is already gone - safe to treat as deleted.',
                responses: [
                    '204' => new OpenApiResponse(description: 'Deleted.'),
                    '403' => new OpenApiResponse(description: 'The result was tracked by somebody else.'),
                    '404' => new OpenApiResponse(description: 'Unknown id, or already deleted.'),
                ],
            ),
            security: "is_granted('ROLE_PAT') or is_granted('ROLE_OAUTH2_SOLVING-TIMES:WRITE')",
            output: false,
            // No state provider exists for this DTO resource - without read: false,
            // ReadProvider resolves null state and 404s before the processor runs.
            read: false,
            processor: DeleteSolvingTimeProcessor::class,
        ),
    ],
)]
final class DeleteSolvingTimeInput
{
}
