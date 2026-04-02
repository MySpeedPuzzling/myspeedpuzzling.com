<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;

#[ApiResource(
    shortName: 'DeleteCollectionItem',
    operations: [
        new Delete(
            uriTemplate: '/v1/me/collections/{collectionId}/items/{itemId}',
            openapi: new OpenApiOperation(tags: ['My Collections']),
            security: "is_granted('ROLE_PAT') or is_granted('ROLE_OAUTH2_COLLECTIONS:WRITE')",
            output: false,
            processor: DeleteCollectionItemProcessor::class,
        ),
    ],
)]
final class DeleteCollectionItemInput
{
}
