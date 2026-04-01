<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Put;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    shortName: 'UpdateCollection',
    operations: [
        new Put(
            uriTemplate: '/v1/me/collections/{collectionId}',
            security: "is_granted('ROLE_PAT') or is_granted('ROLE_OAUTH2_COLLECTIONS:WRITE')",
            output: CollectionResponse::class,
            processor: UpdateCollectionProcessor::class,
        ),
    ],
)]
final class UpdateCollectionInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public string $name = '';

    #[Assert\Length(max: 500)]
    public null|string $description = null;

    public string $visibility = 'private';
}
