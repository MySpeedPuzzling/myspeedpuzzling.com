<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    shortName: 'CreateCollection',
    operations: [
        new Post(
            uriTemplate: '/v1/me/collections',
            openapi: new OpenApiOperation(tags: ['My Collections']),
            security: "is_granted('ROLE_PAT') or is_granted('ROLE_OAUTH2_COLLECTIONS:WRITE')",
            output: CollectionResponse::class,
            processor: CreateCollectionProcessor::class,
        ),
    ],
)]
final class CreateCollectionInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public string $name = '';

    #[Assert\Length(max: 500)]
    public null|string $description = null;

    public string $visibility = 'private';
}
